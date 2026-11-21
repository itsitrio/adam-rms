import { assign, newAsset, newProject, pipelineStatus } from "../projects";
import { BASE_URL } from "../env";
import { dbQuery, expect, formData, newSession, seedTenants, type Session, type Tenant, test } from "../tenants";

/**
 * Whether won work has been paid for. A project's payment falls due a number of days after it ends (the business's
 * payment terms) unless it has its own due date, and it can be marked as in collections or written off. An asset's
 * revenue to date is split the same way, taking the asset to have been paid for in the same proportion as each
 * project's bill; the receivables report lists what's still owed on won projects.
 */

const DAYS_3 = (month: string) => ({ start: `2020-${month}-01 09:00:00`, end: `2020-${month}-03 18:00:00` }); // 3 days at £10.00

function section(body: string, state: string) {
  const start = body.indexOf(`id="receivables_${state}"`);
  if (start < 0) return "";
  const next = body.indexOf('id="receivables_', start + 1);
  return body.slice(start, next < 0 ? undefined : next);
}

function shown(body: string, id: string) {
  return body.match(new RegExp(`id="${id}">([^<]*)<`))?.[1];
}

async function pay(session: Session, t: Tenant, projectId: number, amount: string) {
  const paid = await session.api("/api/projects/newPayment.php", { instances_id: t.instanceId, formData: formData({
    projects_id: projectId, payments_type: 1, payments_amount: amount, payments_quantity: 1,
    payments_date: "2020-12-01", payments_reference: "e2e receivable", payments_method: "", payments_supplier: "", payments_comment: "",
  }) });
  expect(paid.json, "recording the payment").toMatchObject({ result: true });
}

function collection(session: Session, t: Tenant, projectId: number, params: Record<string, string>) {
  return session.api("/api/projects/changePaymentCollection.php", { instances_id: t.instanceId, projects_id: projectId, ...params });
}

test("an asset's revenue to date is split by how much has been collected, and the receivables report lists what's owed", async ({ asA, tenants: { a } }) => {
  const won = pipelineStatus(a, "WON");
  const asset = await newAsset(asA, a);
  const project = async (month: string, open = true) => {
    const id = await newProject(asA, a, a.users.full.id, DAYS_3(month));
    expect((await assign(asA, a, id, asset)).json).toMatchObject({ result: true });
    expect((await asA.api("/api/projects/changeStatus.php", { instances_id: a.instanceId, projects_id: id, projectsStatuses_id: won })).json).toMatchObject({ result: true });
    if (open) expect((await asA.api("/api/projects/data.php", { instances_id: a.instanceId, id })).json?.result, "opening the project").toBe(true);
    return id;
  };

  const halfPaid = await project("01"); // £15.00 of £30.00 paid, and due 30 days after it ended
  await pay(asA, a, halfPaid, "15.00");
  const paid = await project("02");
  await pay(asA, a, paid, "30.00");
  const inCollections = await project("03");
  expect((await collection(asA, a, inCollections, { projects_collectionStatus: "COLLECTIONS" })).json).toMatchObject({ result: true });
  const writtenOff = await project("04");
  expect((await collection(asA, a, writtenOff, { projects_collectionStatus: "WRITTEN_OFF" })).json).toMatchObject({ result: true });
  const notDue = await project("05"); // Its own due date, a long way off
  expect((await collection(asA, a, notDue, { projects_paymentDueDate: "2099-01-01" })).json).toMatchObject({ result: true });
  const neverOpened = await project("06", false);
  expect(dbQuery("SELECT 1 FROM projectsFinanceCache WHERE projects_id = ?", [neverOpened])).toEqual([]);

  const page = await asA.page(`/asset.php?id=${a.assetTypeId}&asset=${asset}`);
  expect(shown(page.body, "assetRevenueEarned")).toBe("£180.00");
  expect(shown(page.body, "assetRevenue_COLLECTED")).toBe("£45.00");
  expect(shown(page.body, "assetRevenue_PAST_DUE")).toBe("£15.00");
  expect(shown(page.body, "assetRevenue_COLLECTIONS")).toBe("£30.00");
  expect(shown(page.body, "assetRevenue_WRITTEN_OFF")).toBe("£30.00");
  expect(shown(page.body, "assetRevenue_NOT_DUE")).toBe("£30.00");
  expect(shown(page.body, "assetRevenue_NOT_CALCULATED")).toBe("£30.00");

  const report = (await asA.page("/receivables.php")).body;
  const link = (id: number) => `/project/?id=${id}"`;
  expect(section(report, "PAST_DUE")).toContain(link(halfPaid));
  expect(section(report, "PAST_DUE")).toMatch(/£15\.00<\/b>/);
  expect(section(report, "COLLECTIONS")).toContain(link(inCollections));
  expect(section(report, "WRITTEN_OFF")).toContain(link(writtenOff));
  expect(section(report, "NOT_DUE")).toContain(link(notDue));
  expect(section(report, "NOT_CALCULATED")).toContain(link(neverOpened));
  // Paid, so in none of the report's sections (the sidebar lists projects too, so not the whole page)
  for (const state of ["NOT_DUE", "PAST_DUE", "COLLECTIONS", "WRITTEN_OFF", "NOT_CALCULATED"]) expect(section(report, state), state).not.toContain(link(paid));

  // The project's Finance tab shows where its payment is up to
  const finance = (await asA.page(`/project/?id=${halfPaid}`)).body;
  expect(shown(finance, "paymentCollectionState")).toBe("Past due");
  expect(finance).toContain("02 Feb 2020"); // 3 January + 30 days
});

test("payment collection can only be set to a real date and a known status, and can be cleared", async ({ asA, tenants: { a } }) => {
  const project = await newProject(asA, a, a.users.full.id, DAYS_3("08"));
  const stored = () => dbQuery<{ due: string | null; status: string | null }>(
    "SELECT DATE_FORMAT(projects_paymentDueDate, '%Y-%m-%d') due, projects_collectionStatus status FROM projects WHERE projects_id = ?", [project],
  )[0];

  for (const bad of [{ projects_paymentDueDate: "2020-13-45" }, { projects_paymentDueDate: "next week" }, { projects_collectionStatus: "PAID" }, {}]) {
    expect((await collection(asA, a, project, bad)).json, JSON.stringify(bad)).toMatchObject({ result: false });
  }
  expect(stored()).toEqual({ due: null, status: null });

  expect((await collection(asA, a, project, { projects_paymentDueDate: "2021-01-31", projects_collectionStatus: "COLLECTIONS" })).json).toMatchObject({ result: true });
  expect(stored()).toEqual({ due: "2021-01-31", status: "COLLECTIONS" });
  expect((await collection(asA, a, project, { projects_paymentDueDate: "", projects_collectionStatus: "" })).json).toMatchObject({ result: true });
  expect(stored()).toEqual({ due: null, status: null });
});

test("a business's payment terms are a whole number of days", async ({ asA, tenants: { a } }) => {
  const terms = () => dbQuery<{ days: number }>("SELECT instances_config_paymentDueDays days FROM instances WHERE instances_id = ?", [a.instanceId])[0].days;
  const set = (value: string) => asA.api("/api/instances/editInstance.php", { instances_id: a.instanceId, formData: formData({ instances_config_paymentDueDays: value }) });
  for (const bad of ["-1", "366", "7.5", "soon"]) expect((await set(bad)).json, bad).toMatchObject({ result: false });
  expect(terms()).toBe(30);
  expect((await set("14")).json).toMatchObject({ result: true });
  expect(terms()).toBe(14);
  expect((await asA.page("/instances/configuration/invoices.php")).body).toContain('id="instances_config_paymentDueDays" value="14"');
  await set("30");
});

test("someone who can see projects but not payments sees an asset's revenue but not how much has been collected", async ({ asA, asLimitedA, tenants: { a } }) => {
  const asset = await newAsset(asA, a);
  const project = await newProject(asA, a, a.users.full.id, DAYS_3("10"));
  await assign(asA, a, project, asset);
  await asA.api("/api/projects/changeStatus.php", { instances_id: a.instanceId, projects_id: project, projectsStatuses_id: pipelineStatus(a, "WON") });

  const url = `/asset.php?id=${a.assetTypeId}&asset=${asset}`;
  expect((await asA.page(url)).body).toContain('id="assetRevenueCollection"');
  const limited = (await asLimitedA.page(url)).body;
  expect(shown(limited, "assetRevenueEarned")).toBe("£30.00");
  expect(limited).not.toContain('id="assetRevenueCollection"');
});

test("the receivables report only lists the business's own projects", async ({ asA, playwright, tenants: { b, password } }) => {
  const asB = await newSession(await playwright.request.newContext({ baseURL: BASE_URL }), b.users.full.email, password);
  const won = pipelineStatus(b, "WON");
  const project = await newProject(asB, b, b.users.full.id, DAYS_3("11"));
  await asB.api("/api/projects/changeStatus.php", { instances_id: b.instanceId, projects_id: project, projectsStatuses_id: won });
  await asB.api("/api/projects/data.php", { instances_id: b.instanceId, id: project });
  // Owed something, so it's on B's report - and not on A's
  dbQuery("UPDATE projectsFinanceCache SET projectsFinanceCache_grandTotal = 1000 WHERE projects_id = ?", [project]);
  expect((await asB.page("/receivables.php")).body).toContain(`/project/?id=${project}"`);
  expect((await asA.page("/receivables.php")).body).not.toContain(`/project/?id=${project}"`);
});

test("the receivables page keeps the sidebar's list of projects", async ({ asA, tenants: { a } }) => {
  // The sidebar lists projects from the same page data, so the page mustn't replace it with its own list
  const sidebarEntry = `<p title="${a.marker} project">`;
  expect((await asA.page("/ledger.php")).body, "control: another page's sidebar").toContain(sidebarEntry);
  expect((await asA.page("/receivables.php")).body).toContain(sidebarEntry);
});

test("the Finance tab says which permission marks a project as in collections, to those without it", async ({ asA, asLimitedA, tenants: { a } }) => {
  // The limited user can't see payments at all; let them, for this test
  dbQuery("UPDATE instancePositions SET instancePositions_actions = CONCAT(instancePositions_actions, ',PROJECTS:PROJECT_PAYMENTS:VIEW') WHERE instancePositions_id = ?", [a.positions.limited]);
  try {
    const limited = (await asLimitedA.page(`/project/?id=${a.projectId}`)).body;
    expect(limited).toContain('id="paymentCollection"');
    expect(limited).toContain('id="paymentCollectionNoPermission"');
    expect(limited).not.toContain('id="paymentCollectionForm"');
  } finally {
    seedTenants();
  }
  const full = (await asA.page(`/project/?id=${a.projectId}`)).body;
  expect(full).toContain('id="paymentCollectionForm"');
  expect(full).not.toContain('id="paymentCollectionNoPermission"');
});

test("a payment falls due after the latest date a project has, so projects without assets get a due date too", async ({ asA, tenants: { a } }) => {
  const dueShown = async (project: number) => (await asA.page(`/project/?id=${project}`)).body.match(/id="paymentCollectionDueDate">\s*([^<]*?)\s*</)?.[1];
  // A project that only has use dates (as one whose type has no assets does) - its terms run from the end of its use
  const created = await asA.api("/api/projects/new.php", {
    instances_id: a.instanceId, projects_name: "Use dates only", projects_manager: a.users.full.id, projectsType_id: a.projectTypeId,
    projects_dates_use_start: "2020-05-01 09:00:00", projects_dates_use_end: "2020-05-03 18:00:00",
  });
  expect(created.json, "creating the project").toMatchObject({ result: true });
  const useOnly: number = created.json.response.projects_id;
  expect(dbQuery("SELECT projects_dates_deliver_end e FROM projects WHERE projects_id = ?", [useOnly])[0]).toMatchObject({ e: null });
  expect(await dueShown(useOnly)).toBe("02 Jun 2020"); // 3 May + 30 days

  // Whichever of its dates is latest wins
  const both = await newProject(asA, a, a.users.full.id, { start: "2020-06-01 09:00:00", end: "2020-06-03 18:00:00" });
  dbQuery("UPDATE projects SET projects_dates_use_start = '2020-06-10 09:00:00', projects_dates_use_end = '2020-06-12 18:00:00' WHERE projects_id = ?", [both]);
  expect(await dueShown(both)).toBe("12 Jul 2020"); // 12 June + 30 days, not 3 June
  dbQuery("UPDATE projects SET projects_dates_use_end = '2020-06-02 18:00:00', projects_dates_use_start = '2020-06-01 09:00:00' WHERE projects_id = ?", [both]);
  expect(await dueShown(both)).toBe("03 Jul 2020"); // 3 June + 30 days

});

test("an invoice's due date can be locked in when it's generated, and unlocked again", async ({ asA, tenants: { a } }) => {
  const project = await newProject(asA, a, a.users.full.id, DAYS_3("09")); // Ends 3 September 2020, so due 3 October on 30 day terms
  const stored = () => dbQuery<{ issue: string | null; due: string | null; terms: number | null }>(
    "SELECT DATE_FORMAT(projects_invoiceIssueDate, '%Y-%m-%d') issue, DATE_FORMAT(projects_invoiceDueDate, '%Y-%m-%d') due, projects_invoiceTermsDays terms FROM projects WHERE projects_id = ?", [project],
  )[0];
  const dueShown = async () => (await asA.page(`/project/?id=${project}`)).body.match(/id="paymentCollectionDueDate">\s*([^<]*?)\s*</)?.[1];
  // The document's script escapes spaces in what it shows
  const invoice = async (extra: string) => { const page = await asA.page(`/project/projectInvoice.php?id=${project}&type=invoice&dueDate=on${extra}`); return { ...page, body: page.body.replace(/\\u0020/g, " ") }; };

  // An invoice shows when it's due even before anything is locked, and a draft or preview locks nothing
  const unlocked = (await invoice("")).body;
  expect(unlocked).toContain("Payment Due:");
  expect(unlocked).toContain("3 Oct 2020");
  await invoice("&upload=true&lockDueDate=on&preview=1");
  await invoice("&draft=true&lockDueDate=on");
  expect(stored()).toEqual({ issue: null, due: null, terms: null });
  expect((await asA.page(`/project/projectInvoice.php?id=${project}&type=invoice`)).body).not.toContain("Payment Due:");

  // Generating the PDF with the option on locks it in, with the date it was issued and the terms it was issued on
  const generated = (await invoice("&upload=true&lockDueDate=on")).body;
  expect(generated).toContain("3 Oct 2020");
  const today = new Date().toISOString().slice(0, 10);
  expect(stored()).toEqual({ issue: today, due: "2020-10-03", terms: 30 });
  expect(await dueShown()).toBe("03 Oct 2020");

  // Later changes to the project's dates or the business's terms don't move it
  const set = (value: string) => asA.api("/api/instances/editInstance.php", { instances_id: a.instanceId, formData: formData({ instances_config_paymentDueDays: value }) });
  expect((await set("14")).json).toMatchObject({ result: true });
  dbQuery("UPDATE projects SET projects_dates_deliver_end = '2020-12-25 18:00:00' WHERE projects_id = ?", [project]);
  expect(await dueShown()).toBe("03 Oct 2020");
  expect((await invoice("")).body).toContain("3 Oct 2020");
  // Locking again changes nothing, and an explicit date can't be locked over it
  expect((await asA.api("/api/projects/changeInvoiceDueDate.php", { instances_id: a.instanceId, projects_id: project, action: "lock", projects_invoiceDueDate: "2021-01-01" })).json).toMatchObject({ result: true, response: { dueDate: "2020-10-03" } });
  expect(stored().due).toBe("2020-10-03");
  // A due date set on the project itself still wins
  expect((await collection(asA, a, project, { projects_paymentDueDate: "2020-11-11" })).json).toMatchObject({ result: true });
  expect(await dueShown()).toBe("11 Nov 2020");
  expect((await collection(asA, a, project, { projects_paymentDueDate: "" })).json).toMatchObject({ result: true });

  // Unlocking goes back to working it out: 25 December + 14 days
  expect((await asA.api("/api/projects/changeInvoiceDueDate.php", { instances_id: a.instanceId, projects_id: project, action: "unlock" })).json).toMatchObject({ result: true });
  expect(stored()).toEqual({ issue: null, due: null, terms: null });
  expect(await dueShown()).toBe("08 Jan 2021");
  await set("30");

  // A date can be chosen when locking, but it has to be a real one, and the action a known one
  for (const bad of [{ action: "lock", projects_invoiceDueDate: "2020-13-45" }, { action: "delete" }]) {
    expect((await asA.api("/api/projects/changeInvoiceDueDate.php", { instances_id: a.instanceId, projects_id: project, ...bad })).json, JSON.stringify(bad)).toMatchObject({ result: false });
  }
  expect((await asA.api("/api/projects/changeInvoiceDueDate.php", { instances_id: a.instanceId, projects_id: project, action: "lock", projects_invoiceDueDate: "2021-02-02" })).json).toMatchObject({ result: true });
  expect(stored().due).toBe("2021-02-02");
});

test("an invoice's due date isn't locked by someone without the payment collection permission", async ({ asLimitedA, asA, tenants: { a } }) => {
  const project = await newProject(asA, a, a.users.full.id, DAYS_3("11"));
  await asLimitedA.page(`/project/projectInvoice.php?id=${project}&type=invoice&dueDate=on&upload=true&lockDueDate=on`);
  expect(dbQuery("SELECT projects_invoiceDueDate d FROM projects WHERE projects_id = ?", [project])[0]).toMatchObject({ d: null });
});
