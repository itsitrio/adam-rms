import { assign, newAsset, newProject, pipelineStatus } from "../projects";
import { BASE_URL } from "../env";
import { dbQuery, expect, formData, newSession, type Session, type Tenant, test } from "../tenants";

/**
 * The reports suite (/reports/). One won project in 2011 - a year no other test uses - with equipment hired at half
 * price, a sales line, a staff line and an additional hire line, part paid, plus an asset bought that year, a repair
 * and an overhead, gives figures every financial report can be checked against:
 *
 *   Equipment £30.00 at rates (3 days at £10.00), less a 50% discount = £15.00
 *   Sales £50.00 (cost £20.00), staff 2 x £100.00 (cost 2 x £60.00), additional hire £40.00 (no cost recorded)
 *   Revenue £305.00, direct costs £140.00, gross margin £165.00
 *   Repair £75.00, depreciation £1,200.00 (bought 1 Jan 2011 for £1,200.00 over 12 months), rent £500.00
 *   Net profit -£1,610.00; £100.00 paid on 10 April, so £205.00 still owed
 */

const YEAR = "period=custom&start=2011-01-01&end=2011-12-31";

/** A CSV download as rows of cells */
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

/** The cells after the first row (after `after`, if given) whose first cell is `label` */
function rowOf(rows: string[][], label: string, after?: string) {
  const start = after ? rows.findIndex((r) => r[0] === after) : 0;
  const row = rows.slice(Math.max(0, start)).find((r) => r[0] === label);
  expect(row, `a row for "${label}"`).toBeTruthy();
  return row!.slice(1);
}

/** The cells of the row that mentions `text` anywhere */
function rowMentioning(rows: string[][], text: string, after?: string) {
  const start = after ? rows.findIndex((r) => r[0] === after) : 0;
  const row = rows.slice(Math.max(0, start)).find((r) => r.some((c) => c.includes(text)));
  expect(row, `a row mentioning "${text}"`).toBeTruthy();
  return row!;
}

async function ok(response: Promise<{ json: any; body: string }>, what: string) {
  const r = await response;
  expect(r.json, `${what}\n${r.body.slice(0, 300)}`).toMatchObject({ result: true });
  return r.json;
}

/** Removes what earlier runs left in 2011, so the year holds only what this run adds */
function clearYear() {
  dbQuery("UPDATE payments SET payments_deleted = 1 WHERE projects_id IN (SELECT projects_id FROM projects WHERE projects_dates_deliver_end BETWEEN '2011-01-01' AND '2011-12-31 23:59:59')");
  dbQuery("UPDATE payments SET payments_deleted = 1 WHERE payments_date BETWEEN '2011-01-01' AND '2011-12-31 23:59:59'");
  dbQuery("UPDATE projects SET projects_deleted = 1 WHERE projects_dates_deliver_end BETWEEN '2011-01-01' AND '2011-12-31 23:59:59'");
  dbQuery("UPDATE expenses SET expenses_deleted = 1 WHERE expenses_date BETWEEN '2011-01-01' AND '2011-12-31'");
  dbQuery("UPDATE maintenanceJobs SET maintenanceJobs_deleted = 1 WHERE maintenanceJobs_timestamp_added BETWEEN '2011-01-01' AND '2011-12-31 23:59:59'");
  dbQuery("UPDATE assets SET assets_deleted = 1 WHERE assets_purchaseDate IS NOT NULL");
}

async function scenario(asA: Session, a: Tenant) {
  clearYear();
  const client = (await ok(asA.api("/api/clients/new.php", { instances_id: a.instanceId, clients_name: `Report client ${Date.now()}` }), "creating the client")).response.clients_id as number;
  const asset = await newAsset(asA, a);
  await ok(asA.api("/api/assets/editAsset.php", { instances_id: a.instanceId, formData: formData({ assets_id: asset, assets_purchaseCost: "1200.00", assets_purchaseDate: "2011-01-01", assets_depreciationMonths: "12" }) }), "setting the purchase cost");

  const project = await newProject(asA, a, a.users.full.id, { start: "2011-03-01 09:00:00", end: "2011-03-03 18:00:00" });
  await ok(asA.api("/api/projects/changeClient.php", { instances_id: a.instanceId, projects_id: project, clients_id: client }), "setting the client");
  await ok(assign(asA, a, project, asset), "assigning the asset");
  dbQuery("UPDATE assetsAssignments SET assetsAssignments_discount = 50 WHERE projects_id = ? AND assets_id = ?", [project, asset]);
  const line = (type: number, amount: string, quantity: number, cost: string, supplier = "") => ok(asA.api("/api/projects/newPayment.php", { instances_id: a.instanceId, formData: formData({
    projects_id: project, payments_type: type, payments_amount: amount, payments_quantity: quantity, payments_cost: cost,
    payments_date: "2011-03-03", payments_reference: "", payments_method: "", payments_supplier: supplier, payments_comment: "e2e report line",
  }) }), `adding a type ${type} line`);
  await line(2, "50.00", 1, "20.00");
  await line(4, "100.00", 2, "60.00");
  await line(3, "40.00", 1, "", "E2E supplier");
  await ok(asA.api("/api/projects/newPayment.php", { instances_id: a.instanceId, formData: formData({
    projects_id: project, payments_type: 1, payments_amount: "100.00", payments_quantity: 1, payments_cost: "999",
    payments_date: "2011-04-10", payments_reference: "E2E-REPORT-PAYMENT", payments_method: "Bank Transfer", payments_supplier: "", payments_comment: "",
  }) }), "recording the payment");
  await ok(asA.api("/api/projects/changeStatus.php", { instances_id: a.instanceId, projects_id: project, projectsStatuses_id: pipelineStatus(a, "WON") }), "winning the project");
  await ok(asA.api("/api/projects/data.php", { instances_id: a.instanceId, id: project }), "opening the project");

  //A repair, opened in 2011
  dbQuery(`INSERT INTO maintenanceJobs (maintenanceJobs_assets, maintenanceJobs_timestamp_added, maintenanceJobs_user_creator, maintenanceJobs_title, maintenanceJobs_priority, instances_id, maintenanceJobs_deleted, maintenanceJobs_flagAssets, maintenanceJobs_blockAssets)
    VALUES (?, '2011-05-01 10:00:00', ?, 'E2E report repair', 5, ?, 0, 0, 0)`, [String(asset), a.users.full.id, a.instanceId]);
  const job = dbQuery<{ id: number }>("SELECT MAX(maintenanceJobs_id) id FROM maintenanceJobs WHERE instances_id = ?", [a.instanceId])[0].id;
  await ok(asA.api("/api/maintenance/job/changeCost.php", { instances_id: a.instanceId, maintenanceJobs_id: job, maintenanceJobs_cost: "75.00" }), "costing the repair");

  await ok(asA.api("/api/reports/expenses/save.php", { instances_id: a.instanceId, expenses_date: "2011-06-01", expenses_category: "Rent", expenses_amount: "500.00", expenses_supplier: "E2E landlord" }), "adding the rent");
  return { client, asset, project, job };
}

test.describe.configure({ mode: "serial" });

test("the financial reports add up one year's revenue, costs, cash and debts", async ({ asA, tenants: { a } }) => {
  const { client, project } = await scenario(asA, a);
  const stored = dbQuery<{ type: number; cost: number | null }>("SELECT payments_type type, payments_cost cost FROM payments WHERE projects_id = ? ORDER BY payments_id", [project]);
  expect(stored).toEqual([{ type: 2, cost: 2000 }, { type: 4, cost: 6000 }, { type: 3, cost: null }, { type: 1, cost: null }]);

  const pnl = await csv(asA, `report=profit-and-loss&${YEAR}`);
  const current = (label: string) => rowOf(pnl, label, "Profit & loss account")[0];
  expect(current("Equipment hire at rates")).toBe("30.00");
  expect(current("Less discounts")).toBe("-15.00");
  expect(current("Sales")).toBe("50.00");
  expect(current("Staff")).toBe("200.00");
  expect(current("Additional hire")).toBe("40.00");
  expect(current("Total revenue")).toBe("305.00");
  expect(current("Cost of sales")).toBe("20.00");
  expect(current("Crew and staff")).toBe("120.00");
  expect(current("Additional hire from suppliers")).toBe("0.00");
  expect(current("Total direct costs")).toBe("140.00");
  expect(current("Gross margin")).toBe("165.00");
  expect(current("Gross margin %")).toBe("54.1%");
  expect(current("Repairs and maintenance")).toBe("75.00");
  expect(current("Depreciation")).toBe("1200.00");
  expect(current("Rent")).toBe("500.00");
  expect(current("Net profit")).toBe("-1610.00");
  expect(pnl.some((r) => r[0].includes("1 sales, additional hire or staff line has no cost recorded"))).toBe(true);

  //Costing the additional hire line takes it off the warnings and the margin
  const subHire = dbQuery<{ id: number }>("SELECT payments_id id FROM payments WHERE projects_id = ? AND payments_type = 3", [project])[0].id;
  await ok(asA.api("/api/projects/changePaymentCost.php", { instances_id: a.instanceId, payments_id: subHire, payments_cost: "25" }), "costing the additional hire");
  const costed = await csv(asA, `report=profit-and-loss&${YEAR}`);
  expect(rowOf(costed, "Gross margin", "Profit & loss account")[0]).toBe("140.00");
  expect(costed.some((r) => r[0].includes("no cost recorded"))).toBe(false);

  const projects = await csv(asA, `report=project-profitability&${YEAR}`);
  expect(rowMentioning(projects, "Booking test 2011-03-01").slice(4, 11)).toEqual(["15.00", "290.00", "305.00", "165.00", "140.00", "45.9%", "0"]);

  const trend = await csv(asA, `report=revenue-trend&${YEAR}`);
  expect(rowOf(trend, "Mar 2011")).toEqual(["1", "15.00", "50.00", "40.00", "200.00", "305.00", "305.00"]);

  const discounts = await csv(asA, `report=discounts&${YEAR}`);
  expect(rowMentioning(discounts, "Booking test 2011-03-01", "Projects with a discount or custom price").slice(4)).toEqual(["1", "30.00", "15.00", "15.00", "50.0%"]);

  const cash = await csv(asA, `report=cash-receipts&${YEAR}`);
  expect(rowOf(cash, "Received")[0]).toBe("100.00");
  expect(rowOf(cash, "Bank Transfer", "By payment method").slice(0, 2)).toEqual(["1", "100.00"]);
  expect(rowMentioning(cash, "E2E-REPORT-PAYMENT")).toContain("100.00");

  const billed = await csv(asA, `report=billed-vs-collected&${YEAR}`);
  expect(rowOf(billed, "Mar 2011").slice(0, 3)).toEqual(["305.00", "0.00", "305.00"]);
  expect(rowOf(billed, "Apr 2011").slice(0, 4)).toEqual(["0.00", "100.00", "-100.00", "205.00"]);

  const debtors = await csv(asA, "report=aged-debtors");
  expect(rowMentioning(debtors, "Booking test 2011-03-01", "By project").slice(5)).toEqual(["Over 90 days", "305.00", "100.00", "205.00"]);

  const statement = await csv(asA, `report=client-statement&client=${client}&${YEAR}`);
  expect(rowOf(statement, "Brought forward")[0]).toBe("0.00");
  expect(rowOf(statement, "Billed")[0]).toBe("305.00");
  expect(rowOf(statement, "Paid")[0]).toBe("100.00");
  expect(rowOf(statement, "Balance")[0]).toBe("205.00");
  expect(rowMentioning(statement, "Bank Transfer").slice(4)).toEqual(["", "100.00", "205.00"]);

  const league = await csv(asA, `report=client-league&${YEAR}`);
  expect(rowMentioning(league, "Report client", "Clients by revenue").slice(2, 6)).toEqual(["1", "305.00", "100.0%", "100.0%"]);

  const maintenance = await csv(asA, `report=maintenance&${YEAR}`);
  expect(rowOf(maintenance, "Jobs opened")[0]).toBe("1");
  expect(rowOf(maintenance, "Repair cost")[0]).toBe("75.00");

  const register = await csv(asA, "report=asset-register&asAt=2011-06-30");
  expect(rowOf(register, "Assets")[0]).toBe("1");
  expect(rowOf(register, "Purchase cost")[0]).toBe("1200.00");
  expect(rowOf(register, "Book value")[0]).toBe("604.93"); //181 of 365 days depreciated

  const pack = await csv(asA, `report=close-pack&${YEAR}`);
  expect(rowOf(pack, "Net profit", "Profit & loss - Profit & loss account")[0]).toBe("-1635.00");
  expect(rowOf(pack, "Bank Transfer", "Cash received - By payment method").slice(0, 2)).toEqual(["1", "100.00"]);
});

test("a report prints on the business's letterhead and downloads as a spreadsheet", async ({ asA, tenants: { a } }) => {
  const print = await asA.page(`/reports/report.php?report=profit-and-loss&${YEAR}&format=print`);
  expect(print.body).toContain("<h1>Profit &amp; loss</h1>");
  expect(print.body).toContain(a.marker);
  expect(print.body).toContain("1 Jan 2011 – 31 Dec 2011");

  const xlsx = await asA.request.get(`/reports/report.php?report=profit-and-loss&${YEAR}&format=xlsx`);
  expect(xlsx.status()).toBe(200);
  expect(xlsx.headers()["content-type"]).toContain("spreadsheetml");
  expect((await xlsx.body()).subarray(0, 2).toString()).toBe("PK");

  const page = await asA.page(`/reports/report.php?report=revenue-trend&${YEAR}`);
  expect(page.body).toContain('id="months-chart"');
  expect(page.body).toContain("Mar 11 · Equipment: £15.00");
});

test("overheads can be added, changed and deleted, and only with valid details", async ({ asA, tenants: { a } }) => {
  const save = (params: Record<string, string | number>) => asA.api("/api/reports/expenses/save.php", { instances_id: a.instanceId, ...params });
  for (const bad of [{ expenses_date: "2011-02-30", expenses_category: "Rent", expenses_amount: "1" }, { expenses_date: "2011-02-01", expenses_category: " ", expenses_amount: "1" }, { expenses_date: "2011-02-01", expenses_category: "Rent", expenses_amount: "lots" }]) {
    expect((await save(bad)).json, JSON.stringify(bad)).toMatchObject({ result: false });
  }
  const id = (await ok(save({ expenses_date: "2012-02-01", expenses_category: "Vans", expenses_amount: "80.50", expenses_reference: "E2E-VAN" }), "adding")).response.expenses_id;
  const stored = () => dbQuery<{ amount: number; category: string; deleted: number }>("SELECT expenses_amount amount, expenses_category category, expenses_deleted deleted FROM expenses WHERE expenses_id = ?", [id])[0];
  expect(stored()).toEqual({ amount: 8050, category: "Vans", deleted: 0 });
  await ok(save({ expenses_id: id, expenses_date: "2012-02-01", expenses_category: "Vehicles", expenses_amount: "90" }), "changing");
  expect(stored()).toEqual({ amount: 9000, category: "Vehicles", deleted: 0 });
  expect((await asA.page("/reports/expenses.php?period=custom&start=2012-01-01&end=2012-12-31")).body).toContain(`id="expense_${id}"`);
  await ok(asA.api("/api/reports/expenses/delete.php", { instances_id: a.instanceId, expenses_id: id }), "deleting");
  expect(stored().deleted).toBe(1);
  expect((await asA.page("/reports/expenses.php?period=custom&start=2012-01-01&end=2012-12-31")).body).not.toContain(`id="expense_${id}"`);
});

test("costs, purchase details and finance settings are checked before they're saved", async ({ asA, tenants: { a } }) => {
  const asset = await newAsset(asA, a);
  for (const bad of [{ assets_purchaseCost: "-5" }, { assets_purchaseDate: "2011-13-01" }, { assets_depreciationMonths: "0" }]) {
    expect((await asA.api("/api/assets/editAsset.php", { instances_id: a.instanceId, formData: formData({ assets_id: asset, ...bad }) })).json, JSON.stringify(bad)).toMatchObject({ result: false });
  }
  expect((await asA.api("/api/maintenance/job/changeCost.php", { instances_id: a.instanceId, maintenanceJobs_id: a.maintenanceJobId, maintenanceJobs_cost: "a lot" })).json).toMatchObject({ result: false });
  const set = (name: string, value: string) => asA.api("/api/instances/editInstance.php", { instances_id: a.instanceId, formData: formData({ [name]: value }) });
  for (const [name, value] of [["instances_config_financialYearStart", "13"], ["instances_config_financialYearStart", "0"], ["instances_config_depreciationMonths", "0"], ["instances_config_depreciationMonths", "five"]]) {
    expect((await set(name, value)).json, `${name}=${value}`).toMatchObject({ result: false });
  }
  await ok(set("instances_config_financialYearStart", "4"), "setting April");
  const page = (await asA.page("/reports/report.php?report=profit-and-loss&period=last-fy")).body;
  expect(page).toMatch(/Last financial year \(\d{4}\/\d{2}\)/);
  expect(page).toMatch(/1 Apr \d{4} – 31 Mar \d{4}/);
  await ok(set("instances_config_financialYearStart", "1"), "setting it back");
});

test("reports only count the business's own projects", async ({ asA, playwright, tenants: { a, b, password } }) => {
  const asB = await newSession(await playwright.request.newContext({ baseURL: BASE_URL }), b.users.full.email, password);
  const projectB = await newProject(asB, b, b.users.full.id, { start: "2011-07-01 09:00:00", end: "2011-07-02 18:00:00" });
  await ok(asB.api("/api/projects/newPayment.php", { instances_id: b.instanceId, formData: formData({
    projects_id: projectB, payments_type: 1, payments_amount: "77.00", payments_quantity: 1, payments_date: "2011-07-05", payments_reference: "E2E-B-ONLY", payments_method: "", payments_supplier: "", payments_comment: "",
  }) }), "B's payment");
  await ok(asB.api("/api/reports/expenses/save.php", { instances_id: b.instanceId, expenses_date: "2011-07-01", expenses_category: "E2E B category", expenses_amount: "1" }), "B's expense");
  for (const report of ["cash-receipts", "profit-and-loss", "close-pack"]) {
    const body = (await asA.page(`/reports/report.php?report=${report}&${YEAR}`)).body;
    expect(body, report).not.toContain("E2E-B-ONLY");
    expect(body, report).not.toContain("E2E B category");
  }
  expect((await asB.page(`/reports/report.php?report=cash-receipts&${YEAR}`)).body).toContain("E2E-B-ONLY");
  //Another business's expense and job can't be changed
  const expenseB = dbQuery<{ id: number }>("SELECT MAX(expenses_id) id FROM expenses WHERE instances_id = ?", [b.instanceId])[0].id;
  expect((await asA.api("/api/reports/expenses/save.php", { instances_id: a.instanceId, expenses_id: expenseB, expenses_date: "2011-07-01", expenses_category: "Hijacked", expenses_amount: "1" })).json).toMatchObject({ result: false });
  expect((await asA.api("/api/reports/expenses/delete.php", { instances_id: a.instanceId, expenses_id: expenseB })).json).toMatchObject({ result: false });
  expect(dbQuery("SELECT expenses_category c, expenses_deleted d FROM expenses WHERE expenses_id = ?", [expenseB])).toEqual([{ c: "E2E B category", d: 0 }]);
  expect((await asA.api("/api/maintenance/job/changeCost.php", { instances_id: a.instanceId, maintenanceJobs_id: b.maintenanceJobId, maintenanceJobs_cost: "1" })).json).toMatchObject({ result: false });
  //A sales line of B's, which B can cost and A can't
  await ok(asB.api("/api/projects/newPayment.php", { instances_id: b.instanceId, formData: formData({
    projects_id: projectB, payments_type: 2, payments_amount: "10.00", payments_quantity: 1, payments_date: "2011-07-01", payments_reference: "", payments_method: "", payments_supplier: "", payments_comment: "",
  }) }), "B's sales line");
  const lineB = dbQuery<{ id: number }>("SELECT MAX(payments_id) id FROM payments WHERE projects_id = ? AND payments_type = 2", [projectB])[0].id;
  expect((await asA.api("/api/projects/changePaymentCost.php", { instances_id: a.instanceId, payments_id: lineB, payments_cost: "1" })).json).toMatchObject({ result: false });
  expect(dbQuery("SELECT payments_cost c FROM payments WHERE payments_id = ?", [lineB])).toEqual([{ c: null }]);
  await ok(asB.api("/api/projects/changePaymentCost.php", { instances_id: b.instanceId, payments_id: lineB, payments_cost: "4.50" }), "B costing its own line");
  expect(dbQuery("SELECT payments_cost c FROM payments WHERE payments_id = ?", [lineB])).toEqual([{ c: 450 }]);
});

test("someone without the reporting permissions can't open the reports", async ({ asLimitedA }) => {
  for (const url of ["/reports/", "/reports/report.php?report=profit-and-loss", "/reports/report.php?report=utilisation", "/reports/report.php?report=profit-and-loss&format=csv", "/reports/expenses.php", "/reports/report.php?report=nonsense"]) {
    expect((await asLimitedA.page(url)).body, url).toContain("Oops! Page not found.");
  }
});
