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
