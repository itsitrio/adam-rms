import fs from "fs";
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
  for (const t of tenants) {
    dbQuery("UPDATE instances SET instances_config_documents = NULL, instances_config_massUnit = 'kg' WHERE instances_id = ?", [t.instanceId]);
    dbQuery("UPDATE userInstances SET userInstances_documentDefaults = NULL WHERE instancePositions_id IN (SELECT instancePositions_id FROM instancePositions WHERE instances_id = ?)", [t.instanceId]);
  }
}

test.afterEach(({ tenants: { a, b } }) => reset(a, b));

test("a business's documents use its names, page size, header and footer", async ({ asA, playwright, tenants: { a, b, password } }) => {
  const asB = await newSession(await playwright.request.newContext({ baseURL: BASE_URL }), b.users.full.email, password);
  const before = await document(asA, a, "deliveryNote");
  expect(before).toContain('text: "Delivery\\u0020Note"');
  expect(before).toContain("pageSize: 'A4'");
  expect(before).toContain("Generated\\u0020by\\u0020AdamRMS");
  expect(before).toContain("if (currentPage === coverPages + 1 && !false) return null;");

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
  expect(manifest).toContain("if (currentPage === coverPages + 1 && !true) return null;");
  expect(manifest).toContain('"text":"Received by: ____"');
  expect(await document(asA, a, "quote")).toContain('text: "Estimate"');
  // Names that weren't set keep their default, and the delivery note's footer stays off other documents
  const invoice = await document(asA, a, "invoice");
  expect(invoice).toContain('text: "Invoice"');
  expect(invoice).not.toContain("Received by: ____");

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
  await expect(page.locator("#pdfSaveDefaults")).toHaveText("Saved as the default for everyone");

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

  // A user's own defaults go on top of the business's, until they go back to the business's
  await open("Quote");
  await expect(page.locator("#pdfClearMyDefaults")).toBeHidden();
  await box("addresses").check();
  await box("venue").uncheck();
  await page.locator("#pdfSaveMyDefaults").click();
  await expect(page.locator("#pdfSaveMyDefaults")).toHaveText("Saved as my default");
  await open("Quote");
  await expect(box("addresses")).toBeChecked();
  await expect(box("venue")).not.toBeChecked();
  await expect(box("checkboxes")).toBeChecked();
  await page.locator("#pdfClearMyDefaults").click();
  await expect(box("addresses")).not.toBeChecked();
  await open("Quote");
  await expect(box("addresses")).not.toBeChecked();
  await expect(box("checkboxes")).toBeChecked();
});

test("a user's own defaults are theirs alone", async ({ asA, asLimitedA, tenants: { a } }) => {
  await ok(asA.api("/api/projects/documentDefaults.php", { defaultsType: "invoice", defaults: { venue: "false", "<script>": "true" } }), "saving my defaults");
  expect((await asA.api("/api/projects/documentDefaults.php", { defaultsType: "receipt", defaults: { venue: "false" } })).json).toMatchObject({ result: false });
  const stored = dbQuery<{ user: number; json: string }>(
    "SELECT users_userid user, userInstances_documentDefaults json FROM userInstances WHERE userInstances_documentDefaults IS NOT NULL",
  );
  expect(stored.map((row) => row.user)).toEqual([a.users.full.id]);
  expect(JSON.parse(stored[0].json).invoice).toEqual({ venue: false });
  expect((await asA.page(`/project/?id=${a.projectId}`)).body).toContain('"invoice":{"venue":false}');
  expect((await asLimitedA.page(`/project/?id=${a.projectId}`)).body).not.toContain('"invoice":{"venue":false}');
});

test("the generate PDF form previews the document as its options change", async ({ page, tenants: { a, password } }) => {
  await cacheCdn(page.context());
  await login(page, a.users.full.email, password);
  await page.setViewportSize({ width: 1400, height: 900 });
  await page.goto(`/project/?id=${a.projectId}`, { waitUntil: "domcontentloaded" });
  await page.locator('.nav-link[href="#file-view"]').click();
  await page.getByRole("button", { name: "Create Quote", exact: true }).click();
  const frame = page.locator("#pdfPreviewFrame");
  await expect(frame).toHaveAttribute("src", /projectInvoice\.php\?.*type=quote.*addresses=on.*preview=1/);
  // The preview is the document itself, made in the page and not downloaded or stored
  const files = () => dbQuery<{ n: number }>("SELECT COUNT(*) n FROM s3files WHERE instances_id = ?", [a.instanceId])[0].n;
  const before = files();
  await expect(page.frameLocator("#pdfPreviewFrame").locator("body")).toHaveAttribute("data-preview-bytes", /^[1-9]\d{3,}$/);
  expect(files()).toBe(before);
  await page.locator('#pdfGenerateModal input[name="addresses"]').uncheck();
  await expect(frame).not.toHaveAttribute("src", /addresses=on/);
});

test("more of a document can be changed: wording, dates, references, logo, colour and text", async ({ asA, tenants: { a } }) => {
  const before = await document(asA, a, "quote");
  expect(before).toContain('"Our Reference:"');
  expect(before).toContain(jsEscaped("1 Jan 2030 09:00am"));
  expect(before).toContain('"v1"');
  expect(before).toContain("fillColor: '#E8E8E8'");

  await ok(asA.api("/api/instances/editInstanceDocuments.php", {
    label_reference: "Job No", label_equipment: "Gear </script>", dateFormat: "M j, Y", timeFormat: "g:i A", reference_quote: "Q-{project}-{version}",
    logoAlign: "left", logoSize: "large", accentColour: "#102030", intro_quote: "Thank you for asking.", signature_quote: "Accepted by",
  }), "saving the settings");
  const quote = await document(asA, a, "quote");
  expect(quote).toContain('"Job No:"');
  expect(quote).not.toContain("Our Reference");
  expect(quote).not.toContain("Gear </script>");
  expect(quote).toContain(jsEscaped("Jan 1, 2030 9:00 AM"));
  expect(quote).toContain(`"Q\\u002D${a.projectId}\\u002D1"`);
  expect(quote).toContain("fillColor: '#102030'");
  expect(quote).toContain("color: '#FFFFFF'");
  expect(quote).toContain('"text":"Thank you for asking."');
  expect(quote).toContain('"Accepted by:"');
  // The opening text, signature line and reference are the quote's alone
  const invoice = await document(asA, a, "invoice");
  expect(invoice).not.toContain("Thank you for asking.");
  expect(invoice).not.toContain("Accepted by");
  expect(invoice).toContain('"v1"');

  for (const bad of [{ dateFormat: "\\e\\v\\i\\l" }, { accentColour: "red" }, { logoSize: "huge" }]) {
    expect((await asA.api("/api/instances/editInstanceDocuments.php", bad)).json, JSON.stringify(bad)).toMatchObject({ result: false });
  }
});

test("settings can be previewed before they are saved", async ({ asA, tenants: { a } }) => {
  const preview = await asA.api(`/project/projectInvoice.php?id=${a.projectId}&type=deliveryNote&preview=1`, { name_deliveryNote: "Manifest", pageSize: "LETTER" });
  expect(preview.body).toContain('text: "Manifest"');
  expect(preview.body).toContain("pageSize: 'LETTER'");
  expect(preview.body).toContain("previewBytes");
  expect(dbQuery("SELECT instances_config_documents json FROM instances WHERE instances_id = ?", [a.instanceId])).toEqual([{ json: null }]);
  expect(await document(asA, a, "deliveryNote")).toContain("pageSize: 'A4'");

  const settings = (await asA.page("/instances/configuration/invoices.php")).body;
  expect(settings).toContain('id="documentPreview"');
});

test("dollars are written with a plain $", async ({ asA, tenants: { a } }) => {
  try {
    dbQuery("UPDATE instances SET instances_config_currency = 'USD' WHERE instances_id = ?", [a.instanceId]);
    const data = await asA.api("/api/projects/data.php", { instances_id: a.instanceId, id: a.projectId });
    expect(data.json.response.FINANCIALS.formattedValue).toMatch(/^\$\d/);
  } finally {
    dbQuery("UPDATE instances SET instances_config_currency = 'GBP' WHERE instances_id = ?", [a.instanceId]);
  }
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

test("the copy that is downloaded is the copy that is stored", async ({ page, asA, tenants: { a, password } }) => {
  // With file storage on the PDF is uploaded and then downloaded. It used to be made twice, and pdfmake draws
  // lines (the accent rule, the signature line) in the wrong place the second time
  await ok(asA.api("/api/instances/editInstanceDocuments.php", { accentColour: "#102030", signature_quote: "Accepted by" }), "saving the settings");
  dbQuery("INSERT INTO config (config_key, config_value) VALUES ('FILES_ENABLED', 'Enabled') ON DUPLICATE KEY UPDATE config_value = 'Enabled'");
  try {
    await cacheCdn(page.context());
    await login(page, a.users.full.email, password);
    let stored = Buffer.alloc(0);
    await page.route("**/api/s3files/uploadProjectInvoice.php", async (route) => {
      stored = route.request().postDataBuffer()!;
      await route.fulfill({ json: { result: true, response: { url: "stored" } } });
    });
    const download = page.waitForEvent("download");
    await page.goto(`/project/projectInvoice.php?id=${a.projectId}&type=quote&assets=1`).catch(() => {});
    const downloaded = fs.readFileSync(await (await download).path());
    expect(downloaded.subarray(0, 5).toString()).toBe("%PDF-");
    expect(stored.includes(downloaded), "the upload holds the downloaded file, byte for byte").toBe(true);
  } finally {
    dbQuery("DELETE FROM config WHERE config_key = 'FILES_ENABLED'");
  }
});

test("a document can start with a cover page", async ({ asA, tenants: { a } }) => {
  await ok(asA.api("/api/instances/editInstanceDocuments.php", { cover_quote: "Cover text by e2e", firstPage: "true" }), "saving the cover text");
  const url = `/project/projectInvoice.php?id=${a.projectId}&type=quote&assets=1`;
  const without = (await asA.page(url)).body;
  expect(without).not.toContain('"Prepared for"');
  expect(without).not.toContain("Cover text by e2e");
  expect(without).toContain("var coverPages = 0;");

  const withCover = (await asA.page(`${url}&coverPage=on`)).body;
  expect(withCover).toContain('"Prepared for"');
  expect(withCover).toContain('"text":"Cover text by e2e"');
  // The cover comes before the rest, and has no page header or footer
  expect(withCover.indexOf('"Prepared for"')).toBeLessThan(withCover.indexOf('"Our Reference:"'));
  expect(withCover).toContain("var coverPages = 1;");
  // Its text is the quote's alone
  expect((await asA.page(`/project/projectInvoice.php?id=${a.projectId}&type=invoice&coverPage=on`)).body).not.toContain("Cover text by e2e");
});

test("the cover page can be the business's own formatted text alone", async ({ asA, tenants: { a } }) => {
  const text = ["# Welcome to {business}", "", "Quote for **{project}**, prepared for *{client}*.", "Second line </script>", "", "- One", "- Two", "", "---", "1. First"].join("\n");
  await ok(asA.api("/api/instances/editInstanceDocuments.php", { cover_quote: text, coverLayout: "custom", coverAlign: "right" }), "saving the cover");
  const body = (await asA.page(`/project/projectInvoice.php?id=${a.projectId}&type=quote&coverPage=on`)).body;
  const stack = JSON.parse(body.match(/\{ stack: (\[.*\]), alignment: 'right' \},/)![1]);
  expect(stack).toMatchObject([
    { text: [{ text: `Welcome to ${a.marker} Ltd` }], fontSize: 28, bold: true },
    { text: [{ text: "Quote for " }, { text: `${a.marker} project`, bold: true }, { text: ", prepared for " }, { text: `${a.marker} client`, italics: true }, { text: "." }, { text: "\n" }, { text: "Second line </script>" }] },
    { ul: [{ text: [{ text: "One" }] }, { text: [{ text: "Two" }] }] },
    { canvas: [{ type: "line" }] },
    { ol: [{ text: [{ text: "First" }] }] },
  ]);
  // Nothing typed gets out of the text it is in, and the standard cover's own lines are gone
  expect(body).not.toContain("Second line </script>");
  expect(body).not.toContain('"Prepared for"');
  expect((await asA.api("/api/instances/editInstanceDocuments.php", { coverLayout: "fancy" })).json).toMatchObject({ result: false });
});

test("footers are formatted too, and keep what was typed as text", async ({ asA, tenants: { a } }) => {
  const terms = dbQuery<{ terms: string }>("SELECT instances_quoteTerms terms FROM instances WHERE instances_id = ?", [a.instanceId])[0].terms;
  try {
    await ok(asA.api("/api/instances/editInstance.php", { formData: formData({ instances_quoteTerms: "## Deposit Policy\nA **50%** deposit for {project} & <b>more</b>" }) }), "saving the footer");
    const body = (await asA.page(`/project/projectInvoice.php?id=${a.projectId}&type=quote&paymentTerms=on`)).body;
    expect(body).toContain('"text":[{"text":"Deposit Policy"}],"fontSize":20,"bold":true');
    expect(body).toContain(`{"text":"A "},{"text":"50%","bold":true},{"text":" deposit for ${a.marker} project \\u0026 more"}`);
    expect((await asA.page(`/project/projectInvoice.php?id=${a.projectId}&type=quote`)).body).not.toContain("Deposit Policy");
  } finally {
    dbQuery("UPDATE instances SET instances_quoteTerms = ? WHERE instances_id = ?", [terms, a.instanceId]);
  }
});

test("the cover's text can start new pages, which are part of the cover", async ({ asA, tenants: { a } }) => {
  await ok(asA.api("/api/instances/editInstanceDocuments.php", { cover_quote: "Welcome\n===\n## Terms\nPay on time\n===\nMore terms", coverLayout: "custom", coverAlign: "left" }), "saving the cover");
  const body = (await asA.page(`/project/projectInvoice.php?id=${a.projectId}&type=quote&coverPage=on`)).body;
  const stack = JSON.parse(body.match(/\{ stack: (\[.*\]), alignment: 'left' \},/)![1]);
  expect(stack.map((node: any) => node.pageBreak ?? null)).toEqual([null, "after", null, null, "after", null]);
  // Three pages of cover without the page header and footer
  expect(body).toContain("var coverPages = 3;");
});

test("a cover can hold pages of terms", async ({ asA, tenants: { a } }) => {
  const long = "Terms ".repeat(4500).trim(); // 26,999 characters
  await ok(asA.api("/api/instances/editInstanceDocuments.php", { cover_quote: long, cover_invoice: long, cover_deliveryNote: long }), "saving long covers");
  const stored = JSON.parse(dbQuery<{ json: string }>("SELECT instances_config_documents json FROM instances WHERE instances_id = ?", [a.instanceId])[0].json);
  expect(stored.cover.deliveryNote).toBe(long);
  await ok(asA.api("/api/instances/editInstanceDocuments.php", { cover_quote: "x".repeat(30010) }), "saving a cover that is too long");
  const cut = JSON.parse(dbQuery<{ json: string }>("SELECT instances_config_documents json FROM instances WHERE instances_id = ?", [a.instanceId])[0].json);
  expect(cut.cover.quote).toHaveLength(30000);
});
