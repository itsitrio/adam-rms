import { newAsset } from "../projects";
import { dbQuery, expect, formData, type Session, type Tenant, test } from "../tenants";

/**
 * Loans & finance. A £10,000.00 loan from a member of the business, started 1 Jan 2013, repaid at £500.00 a month from
 * 1 Feb 2013 - a year no other test uses:
 *
 *   1 Feb  repayment £500.00, of which £50.00 interest   → owes £9,550.00
 *   15 Mar fee added £20.00                              → owes £9,570.00
 *   1 Apr  £1,000.00 more borrowed                       → owes £10,570.00
 *
 * Interest and fees for 2013: £70.00. An asset bought with it for £1,200.00 on 1 Jan 2013, depreciated over 24 months,
 * has a book value of £600.00 at the end of 2013.
 */

const YEAR = "period=custom&start=2013-01-01&end=2013-12-31";

function parseCsv(text: string) {
  const rows: string[][] = [];
  let row: string[] = [];
  let cell = "";
  let quoted = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (quoted) {
      if (c === '"' && text[i + 1] === '"') { cell += '"'; i++; }
      else if (c === '"') quoted = false;
      else cell += c;
    } else if (c === '"') quoted = true;
    else if (c === ",") { row.push(cell); cell = ""; }
    else if (c === "\n") { row.push(cell); rows.push(row); row = []; cell = ""; }
    else if (c !== "\r" && c !== "﻿") cell += c;
  }
  if (cell !== "" || row.length) { row.push(cell); rows.push(row); }
  return rows;
}

async function csv(session: Session, query: string) {
  const response = await session.page(`/reports/report.php?${query}&format=csv`);
  expect(response.status, query).toBe(200);
  return parseCsv(response.body);
}

function rowWith(rows: string[][], text: string, after?: string) {
  const start = after ? rows.findIndex((r) => r[0] === after) : 0;
  expect(start, `a section "${after}"`).toBeGreaterThanOrEqual(0);
  const row = rows.slice(start).find((r) => r.some((c) => c === text));
  expect(row, `a row with "${text}"`).toBeTruthy();
  return row!;
}

async function ok(response: Promise<{ json: any; body: string }>, what: string) {
  const r = await response;
  expect(r.json, `${what}\n${r.body.slice(0, 300)}`).toMatchObject({ result: true });
  return r.json;
}

async function newLoan(asA: Session, a: Tenant, name: string, extra: Record<string, string | number> = {}) {
  return (await ok(asA.api("/api/reports/liabilities/save.php", {
    instances_id: a.instanceId, liabilities_name: name, liabilities_type: "MEMBER_LOAN", users_userid: a.users.full.id, liabilities_amount: "10000.00",
    liabilities_startDate: "2013-01-01", liabilities_interestRate: "6.5", liabilities_repaymentAmount: "500.00", liabilities_repaymentFrequency: "MONTHLY",
    liabilities_firstRepaymentDate: "2013-02-01", ...extra,
  }), `adding ${name}`)).response.liabilities_id as number;
}

function transaction(asA: Session, a: Tenant, liability: number, type: string, date: string, amount: string, interest = "") {
  return asA.api("/api/reports/liabilities/transaction.php", {
    instances_id: a.instanceId, liabilities_id: liability, liabilityTransactions_type: type, liabilityTransactions_date: date,
    liabilityTransactions_amount: amount, liabilityTransactions_interest: interest,
  });
}

test.describe.configure({ mode: "serial" });

test("a loan's repayments, interest and fees give what's still owed, the finance costs and the schedule", async ({ asA, tenants: { a } }) => {
  dbQuery("UPDATE liabilities SET liabilities_deleted = 1 WHERE instances_id = ? AND liabilities_startDate BETWEEN '2013-01-01' AND '2013-12-31'", [a.instanceId]);
  const name = `E2E member loan ${Date.now()}`;
  const loan = await newLoan(asA, a, name);
  await ok(transaction(asA, a, loan, "REPAYMENT", "2013-02-01", "500.00", "50.00"), "the repayment");
  await ok(transaction(asA, a, loan, "CHARGE", "2013-03-15", "20.00"), "the fee");
  await ok(transaction(asA, a, loan, "DRAWDOWN", "2013-04-01", "1000.00"), "borrowing more");
  const asset = await newAsset(asA, a);
  await ok(asA.api("/api/assets/editAsset.php", { instances_id: a.instanceId, formData: formData({ assets_id: asset, assets_purchaseCost: "1200.00", assets_purchaseDate: "2013-01-01", assets_depreciationMonths: "24" }) }), "the purchase cost");
  await ok(asA.api("/api/reports/liabilities/asset.php", { instances_id: a.instanceId, assets_id: asset, liabilities_id: loan }), "linking the asset");

  const page = (await asA.page(`/reports/liability.php?id=${loan}`)).body;
  for (const [id, value] of [["liabilityBorrowed", "£11,000.00"], ["liabilityRepaid", "£450.00"], ["liabilityCosts", "£70.00"], ["liabilityBalance", "£10,570.00"]]) {
    expect(page.match(new RegExp(`id="${id}">([^<]*)<`))?.[1], id).toBe(value);
  }
  expect(page).toContain(`id="liabilityAsset_${asset}"`);
  expect(page).toContain("Full E2E_TENANT_A_SECRET"); // The member who lent it

  const report = await csv(asA, "report=liabilities&asAt=2013-12-31");
  expect(rowWith(report, name, "What the business owes")).toEqual([name, "Loan from a member", expect.stringContaining("E2E_TENANT_A_SECRET"), "2013-01-01", "6.5%",
    "11000.00", "450.00", "70.00", "10570.00", "2014-01-01", "1", "600.00"]);
  expect(rowWith(report, "Due in the next 12 months")[1]).toBe("6000.00");
  expect(rowWith(report, "Jan 2014", "Repayments due in the next 12 months").slice(1)).toEqual(["1", "500.00"]);

  //Before it started it isn't owed; after the repayment, before the fee
  expect((await csv(asA, "report=liabilities&asAt=2012-12-31")).some((r) => r.includes(name))).toBe(false);
  expect(rowWith(await csv(asA, "report=liabilities&asAt=2013-02-28"), name, "What the business owes")[8]).toBe("9550.00");

  const pnl = await csv(asA, `report=profit-and-loss&${YEAR}`);
  expect(rowWith(pnl, "Interest and fees on loans and finance", "Profit & loss account")[1]).toBe("70.00");

  const pack = await csv(asA, `report=close-pack&${YEAR}`);
  expect(rowWith(pack, name, "Loans & finance (at the end of the period) - What the business owes")[8]).toBe("10570.00");

  const register = await csv(asA, "report=asset-register&asAt=2013-12-31&detail=1");
  expect(rowWith(register, name, "Every asset")).toBeTruthy();

  //Deleting it keeps the asset, but not the link
  await ok(asA.api("/api/reports/liabilities/delete.php", { instances_id: a.instanceId, liabilities_id: loan }), "deleting");
  expect(dbQuery("SELECT liabilities_id l, assets_deleted d FROM assets WHERE assets_id = ?", [asset])).toEqual([{ l: null, d: 0 }]);
  expect((await csv(asA, "report=liabilities&asAt=2013-12-31")).some((r) => r.includes(name))).toBe(false);
});

test("a liability and its transactions are checked before they're saved, and can be changed", async ({ asA, tenants: { a, b } }) => {
  const save = (params: Record<string, string | number>) => asA.api("/api/reports/liabilities/save.php", { instances_id: a.instanceId, liabilities_name: "E2E checked loan", liabilities_type: "LOAN", liabilities_amount: "100", liabilities_startDate: "2013-06-01", ...params });
  for (const bad of [{ liabilities_name: " " }, { liabilities_type: "GIFT" }, { liabilities_amount: "-5" }, { liabilities_amount: "" }, { liabilities_startDate: "2013-02-30" },
    { liabilities_endDate: "2013-01-01" }, { liabilities_interestRate: "lots" }, { liabilities_repaymentFrequency: "HOURLY" }, { users_userid: b.users.full.id }]) {
    expect((await save(bad)).json, JSON.stringify(bad)).toMatchObject({ result: false });
  }
  const loan = (await ok(save({}), "adding")).response.liabilities_id;
  await ok(save({ liabilities_id: loan, liabilities_name: "E2E renamed loan", liabilities_amount: "250.50" }), "changing");
  expect(dbQuery("SELECT liabilities_name n, liabilities_amount a FROM liabilities WHERE liabilities_id = ?", [loan])).toEqual([{ n: "E2E renamed loan", a: 25050 }]);

  for (const [type, amount, interest] of [["REFUND", "1", ""], ["REPAYMENT", "10", "11"], ["REPAYMENT", "", ""], ["CHARGE", "x", ""]]) {
    expect((await transaction(asA, a, loan, type, "2013-07-01", amount, interest)).json, `${type} ${amount} ${interest}`).toMatchObject({ result: false });
  }
  const id = (await ok(transaction(asA, a, loan, "CHARGE", "2013-07-01", "5", "3"), "a charge")).response.liabilityTransactions_id;
  //A charge has no interest part - all of it is interest or a fee
  expect(dbQuery("SELECT liabilityTransactions_amount a, liabilityTransactions_interest i FROM liabilityTransactions WHERE liabilityTransactions_id = ?", [id])).toEqual([{ a: 500, i: 0 }]);
  await ok(asA.api("/api/reports/liabilities/transaction.php", { instances_id: a.instanceId, liabilities_id: loan, liabilityTransactions_id: id, liabilityTransactions_type: "REPAYMENT", liabilityTransactions_date: "2013-07-02", liabilityTransactions_amount: "8", liabilityTransactions_interest: "1" }), "changing it");
  expect(dbQuery("SELECT liabilityTransactions_type t, liabilityTransactions_amount a, liabilityTransactions_interest i FROM liabilityTransactions WHERE liabilityTransactions_id = ?", [id])).toEqual([{ t: "REPAYMENT", a: 800, i: 100 }]);
  await ok(asA.api("/api/reports/liabilities/deleteTransaction.php", { instances_id: a.instanceId, liabilityTransactions_id: id }), "deleting it");
  expect(dbQuery("SELECT liabilityTransactions_deleted d FROM liabilityTransactions WHERE liabilityTransactions_id = ?", [id])).toEqual([{ d: 1 }]);
  await ok(asA.api("/api/reports/liabilities/delete.php", { instances_id: a.instanceId, liabilities_id: loan }), "tidying up");
});

test("a liability's schedule keeps to the day of the month and stops once it's paid off", async ({ asA, tenants: { a } }) => {
  const name = `E2E short loan ${Date.now()}`;
  const loan = await newLoan(asA, a, name, { liabilities_amount: "1100.00", liabilities_startDate: "2013-01-15", liabilities_firstRepaymentDate: "2013-01-31", users_userid: "", liabilities_lender: "E2E bank" });
  const report = await csv(asA, "report=liabilities&asAt=2013-01-20");
  expect(rowWith(report, name, "What the business owes")[9]).toBe("2013-01-31");
  expect(rowWith(report, "Due in the next 12 months")[1]).toBe("1100.00"); // Two of £500.00, then £100.00
  expect(rowWith(report, "Feb 2013", "Repayments due in the next 12 months").slice(1)).toEqual(["1", "500.00"]); // 28 Feb
  expect(rowWith(report, "Mar 2013", "Repayments due in the next 12 months").slice(1)).toEqual(["1", "100.00"]);
  expect(rowWith(report, "Apr 2013", "Repayments due in the next 12 months").slice(1)).toEqual(["0", "0.00"]);
  await ok(asA.api("/api/reports/liabilities/delete.php", { instances_id: a.instanceId, liabilities_id: loan }), "tidying up");
});

test("someone who can see reports but not liabilities doesn't see them in the close pack", async ({ asA, tenants: { a } }) => {
  const actions = dbQuery<{ a: string }>("SELECT instancePositions_actions a FROM instancePositions WHERE instancePositions_id = ?", [a.positions.full])[0].a;
  dbQuery("UPDATE instancePositions SET instancePositions_actions = ? WHERE instancePositions_id = ?", [actions.split(",").filter((x) => !x.startsWith("FINANCE:LIABILITIES")).join(","), a.positions.full]);
  try {
    const pack = await csv(asA, `report=close-pack&${YEAR}`);
    expect(pack.some((r) => r[0].startsWith("Loans & finance"))).toBe(false);
    expect((await asA.page("/reports/liabilities.php")).body).toContain("Oops! Page not found.");
  } finally {
    dbQuery("UPDATE instancePositions SET instancePositions_actions = ? WHERE instancePositions_id = ?", [actions, a.positions.full]);
  }
});
