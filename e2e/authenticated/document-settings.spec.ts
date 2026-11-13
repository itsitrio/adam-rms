import { cacheCdn } from "../cdn";
import { login } from "../fixtures";
import { BASE_URL } from "../env";
import { dbQuery, expect, formData, newSession, type Session, type Tenant, test } from "../tenants";

/**
 * Each business chooses how its generated documents (quotes, invoices, delivery notes) look - what each is called,
 * the page size, the text in the page header and footer and which options are ticked to start with - and whether
 * masses are shown and entered in kilograms or pounds. Masses are always stored in kg.
 *
 * The seeded asset type weighs 1kg, and the seeded project has one asset of it.
 */

async function ok(response: Promise<{ json: any }>, what: string) {
  expect((await response).json, what).toMatchObject({ result: true });
}

/** The PDF is built in the browser from a script, where Twig's JS escaping writes spaces and the like as " " */
function jsEscaped(text: string) {
  return text.replace(/[^A-Za-z0-9,._]/g, (c) => `\\u${c.charCodeAt(0).toString(16).toUpperCase().padStart(4, "0")}`);
}

async function document(session: Session, t: Tenant, type: "invoice" | "quote" | "deliveryNote") {
  const page = await session.page(`/project/projectInvoice.php?id=${t.projectId}&type=${type}&assets=1&masses=1&totalmass=1&paymentTerms=1`);
  expect(page.status, `opening the ${type}`).toBe(200);
  return page.body;
}

/** Other specs expect the settings a new business has */
function reset(...tenants: Tenant[]) {
  for (const t of tenants) dbQuery("UPDATE instances SET instances_config_documents = NULL, instances_config_massUnit = 'kg' WHERE instances_id = ?", [t.instanceId]);
}

test.afterEach(({ tenants: { a, b } }) => reset(a, b));

test("a business's documents use its names, page size, header and footer", async ({ asA, playwright, tenants: { a, b, password } }) => {
  const asB = await newSession(await playwright.request.newContext({ baseURL: BASE_URL }), b.users.full.email, password);
  const before = await document(asA, a, "deliveryNote");
  expect(before).toContain('text: "Delivery\\u0020Note"');
  expect(before).toContain("pageSize: 'A4'");
  expect(before).toContain("Generated\\u0020by\\u0020AdamRMS");
  expect(before).toContain("if (currentPage === 1 && !false) return null;");

  await ok(asA.api("/api/instances/editInstanceDocuments.php", {
    pageSize: "LETTER", name_deliveryNote: "Manifest", name_quote: "Estimate", headerText: "Header by e2e", footerText: "Footer by e2e",
    firstPage: "true", deliveryNoteFooter: "Received by: ____",
  }), "saving the settings");

  const manifest = await document(asA, a, "deliveryNote");
  expect(manifest).toContain('text: "Manifest"');
  expect(manifest).not.toContain("Delivery\\u0020Note");
  expect(manifest).toContain("pageSize: 'LETTER'");
  expect(manifest).toContain(jsEscaped("Header by e2e"));
  expect(manifest).toContain(jsEscaped("Footer by e2e"));
  expect(manifest).not.toContain("Generated\\u0020by\\u0020AdamRMS");
  expect(manifest).toContain("if (currentPage === 1 && !true) return null;");
  expect(manifest).toContain(jsEscaped("Received by: ____"));
  expect(await document(asA, a, "quote")).toContain('text: "Estimate"');
  // Names that weren't set keep their default, and the delivery note's footer stays off other documents
  const invoice = await document(asA, a, "invoice");
  expect(invoice).toContain('text: "Invoice"');
  expect(invoice).not.toContain(jsEscaped("Received by: ____"));

  const project = (await asA.page(`/project/?id=${a.projectId}`)).body;
  expect(project).toContain("Create Manifest");
  expect(project).toContain("Create Estimate");
  expect(project).toContain("Create Invoice");

  // Only what is sent changes
  await ok(asA.api("/api/instances/editInstanceDocuments.php", { name_deliveryNote: "" }), "clearing one name");
  const cleared = await document(asA, a, "deliveryNote");
  expect(cleared).toContain('text: "Delivery\\u0020Note"');
  expect(cleared).toContain("pageSize: 'LETTER'");

  // Another business's documents are its own
  const other = await document(asB, b, "deliveryNote");
  expect(other).toContain("pageSize: 'A4'");
  expect(other).not.toContain(jsEscaped("Header by e2e"));
});

test("settings that aren't recognised are refused or ignored", async ({ asA, tenants: { a } }) => {
  expect((await asA.api("/api/instances/editInstanceDocuments.php", { pageSize: "A0" })).json).toMatchObject({ result: false });
  expect((await asA.api("/api/instances/editInstanceDocuments.php", { defaultsType: "receipt", defaults: { assets: "false" } })).json).toMatchObject({ result: false });
  await ok(asA.api("/api/instances/editInstanceDocuments.php", { defaultsType: "quote", defaults: { assets: "false", "<script>": "true", subProjects: "true" } }), "saving defaults");
  const stored = JSON.parse(dbQuery<{ json: string }>("SELECT instances_config_documents json FROM instances WHERE instances_id = ?", [a.instanceId])[0].json);
  expect(stored.defaults.quote).toEqual({ assets: false });

  await ok(asA.api("/api/instances/editInstance.php", { formData: formData({ instances_phone: "01234 567890", instances_config_massUnit: "stone" }) }), "an unknown unit");
  expect(dbQuery("SELECT instances_config_massUnit unit FROM instances WHERE instances_id = ?", [a.instanceId])).toEqual([{ unit: "kg" }]);
});

test("the generate PDF form starts with the options saved for each type of document", async ({ page, asA, tenants: { a, password } }) => {
  await cacheCdn(page.context());
  await login(page, a.users.full.email, password);
  const box = (name: string) => page.locator(`#pdfGenerateModal input[name="${name}"]`);
  const open = async (type: "Quote" | "Invoice" | "Delivery Note") => {
    await page.goto(`/project/?id=${a.projectId}`, { waitUntil: "domcontentloaded" });
    await page.locator('.nav-link[href="#file-view"]').click();
    await page.getByRole("button", { name: `Create ${type}`, exact: true }).click();
    await expect(page.locator("#pdfGenerateModal .modal-title")).toHaveText(`Create ${type}`);
  };

  await open("Quote");
  await expect(box("addresses")).toBeChecked();
  await expect(box("checkboxes")).not.toBeChecked();
  await box("addresses").uncheck();
  await box("checkboxes").check();
  await page.locator("#pdfSaveDefaults").click();
  await expect(page.locator("#pdfSaveDefaults")).toHaveText("Saved as the default");

  await open("Quote");
  await expect(box("addresses")).not.toBeChecked();
  await expect(box("checkboxes")).toBeChecked();
  await expect(box("venue")).toBeChecked();

  // Each type has its own, and a delivery note's hidden finance options stay off
  await open("Invoice");
  await expect(box("addresses")).toBeChecked();
  await expect(box("checkboxes")).not.toBeChecked();
  await ok(asA.api("/api/instances/editInstanceDocuments.php", { defaultsType: "deliveryNote", defaults: { finance: "true", comments: "true" } }), "saving delivery note defaults");
  await open("Delivery Note");
  await expect(box("comments")).toBeChecked();
  await expect(box("finance")).not.toBeChecked();
  await expect(box("addresses")).toBeChecked();
});

test("masses are shown and entered in the business's unit, and stored in kg", async ({ asA, playwright, tenants: { a, b, password } }) => {
  const asB = await newSession(await playwright.request.newContext({ baseURL: BASE_URL }), b.users.full.email, password);
  expect((await asA.page(`/asset.php?id=${a.assetTypeId}`)).body).toContain("1.00kg");
  await ok(asA.api("/api/instances/editInstance.php", { formData: formData({ instances_config_massUnit: "lb" }) }), "choosing pounds");

  const asset = (await asA.page(`/asset.php?id=${a.assetTypeId}`)).body;
  expect(asset).toContain("2.20lb");
  expect(asset).not.toContain("1.00kg");
  expect(asset).toContain('name="assetTypes_mass" value="2.205"');
  const deliveryNote = await document(asA, a, "deliveryNote");
  expect(deliveryNote).toContain("2.20lb");
  expect(deliveryNote).not.toMatch(/\d(\.\d+)?kg/);
  const data = await asA.api("/api/projects/data.php", { instances_id: a.instanceId, id: a.projectId });
  expect(data.json.response.FINANCIALS.formattedMass).toBe("2.20lb");
  expect(data.json.response.FINANCIALS.mass).toBe(1);

  // What the form sends in pounds is stored in kg; without massUnit (the app) it is kg as before
  const typeMass = () => Number(dbQuery<{ mass: string }>("SELECT assetTypes_mass mass FROM assetTypes WHERE assetTypes_id = ?", [a.assetTypeId])[0].mass);
  const type = dbQuery<{ name: string; manufacturer: number }>("SELECT assetTypes_name name, manufacturers_id manufacturer FROM assetTypes WHERE assetTypes_id = ?", [a.assetTypeId])[0];
  const edit = (fields: Record<string, string | number>) => asA.api("/api/assets/editAssetType.php", {
    instances_id: a.instanceId, formData: formData({ assetTypes_id: a.assetTypeId, assetTypes_name: type.name, manufacturers_id: type.manufacturer, assetTypes_dayRate: "10.00", assetTypes_weekRate: "30.00", assetTypes_value: "100.00", ...fields }),
  });
  await ok(edit({ assetTypes_mass: 10, massUnit: "lb" }), "editing the type in pounds");
  expect(typeMass()).toBeCloseTo(4.53592, 5);
  await ok(edit({ assetTypes_mass: 1 }), "editing the type in kg");
  expect(typeMass()).toBe(1);

  // Another business keeps its own unit
  expect((await asB.page(`/asset.php?id=${b.assetTypeId}`)).body).toContain("1.00kg");
});
