import { cacheCdn } from "../cdn";
import { login } from "../fixtures";
import { assign, documentSummary, expectFinances, type Finances, newAsset, newProject } from "../projects";
import { dbQuery, expect, formData, type Session, type Tenant, test } from "../tenants";

/**
 * A project's asset lines can be hidden from its quotes and invoices - cases and accessories that come with other
 * equipment, which matter for picking and checking but not to the client - and, separately, left out of its totals
 * so they aren't charged for. Hidden lines still show on delivery notes.
 *
 * The seeded asset type costs £10.00 a day or £30.00 a week, and each asset is worth £100.00 and weighs 1kg.
 * Amounts are in pence.
 */

async function ok(response: Promise<{ json: any }>, what: string) {
  expect((await response).json, what).toMatchObject({ result: true });
}

function assignmentOf(projectId: number, assetId: number) {
  return dbQuery<{ id: number }>(
    "SELECT assetsAssignments_id id FROM assetsAssignments WHERE projects_id = ? AND assets_id = ? AND assetsAssignments_deleted = 0", [projectId, assetId],
  )[0].id;
}

/** The PDF is built in the browser from a script, where Twig's JS escaping writes spaces and the like as " " */
function jsEscaped(text: string) {
  return text.replace(/[^A-Za-z0-9,._]/g, (c) => `\\u${c.charCodeAt(0).toString(16).toUpperCase().padStart(4, "0")}`);
}

/** A new asset type of its own with `count` assets, so a quote can be checked for its name */
async function typeWithAssets(session: Session, t: Tenant, name: string, count: number) {
  const type = await session.api("/api/assets/newAssetType.php", { instances_id: t.instanceId, formData: formData({
    manufacturers_id: t.manufacturerId, assetCategories_id: t.categoryId, assetTypes_name: name, assetTypes_dayRate: "10.00",
  }) });
  expect(type.json, "creating the asset type").toMatchObject({ result: true });
  const assets: number[] = [];
  for (let i = 0; i < count; i++) {
    const asset = await session.api("/api/assets/newAssetFromType.php", { instances_id: t.instanceId, formData: formData({ assetTypes_id: type.json.response.assetTypes_id }) });
    assets.push(asset.json.response.assets_id);
  }
  return assets;
}

test("a line left out of the totals isn't charged for, and its running totals keep up through every kind of change", async ({ asA, tenants: { a } }) => {
  const api = (endpoint: string, params: Parameters<Session["api"]>[1]) => asA.api(endpoint, { instances_id: a.instanceId, ...params });
  // Three days
  const project = await newProject(asA, a, a.users.full.id, { start: "2036-10-01 09:00:00", end: "2036-10-03 18:00:00" });
  const charged = await newAsset(asA, a);
  const accessory = await newAsset(asA, a);
  await ok(assign(asA, a, project, charged), "assigning the charged asset");
  await ok(assign(asA, a, project, accessory), "assigning the accessory");
  const line = assignmentOf(project, accessory);
  const zero: Finances = { subTotal: 0, discounts: 0, total: 0, received: 0, sales: 0, subHire: 0, staff: 0, grandTotal: 0, value: 0, mass: 0 };
  let now: Finances = { ...zero, subTotal: 6000, total: 6000, grandTotal: 6000, value: 20000, mass: 2 };
  await expectFinances(asA, a, project, now);

  await test.step("hiding the accessory from quotes doesn't change the totals", async () => {
    await ok(api("/api/projects/assets/setHidden.php", { assetsAssignments: [line], assetsAssignments_hidden: 1 }), "hiding it");
    expect(dbQuery("SELECT assetsAssignments_hidden h, assetsAssignments_excludedFromTotals x FROM assetsAssignments WHERE assetsAssignments_id = ?", [line])).toEqual([{ h: 1, x: 0 }]);
    await expectFinances(asA, a, project, now);
  });

  await test.step("leaving it out of the totals takes its price off, but not its mass or value", async () => {
    await ok(api("/api/projects/assets/setHidden.php", { assetsAssignments: [line], assetsAssignments_excludedFromTotals: 1 }), "leaving it out");
    // Setting one flag leaves the other as it was
    expect(dbQuery("SELECT assetsAssignments_hidden h, assetsAssignments_excludedFromTotals x FROM assetsAssignments WHERE assetsAssignments_id = ?", [line])).toEqual([{ h: 1, x: 1 }]);
    now = { ...now, subTotal: 3000, total: 3000, grandTotal: 3000 };
    await expectFinances(asA, a, project, now);
    // Asking again changes nothing
    await ok(api("/api/projects/assets/setHidden.php", { assetsAssignments: [line], assetsAssignments_excludedFromTotals: 1 }), "leaving it out again");
    await expectFinances(asA, a, project, now);
  });

  await test.step("its discount and custom price don't count", async () => {
    await ok(api("/api/projects/assets/setDiscount.php", { assetsAssignments: [line], assetsAssignments_discount: 50 }), "discounting it");
    await expectFinances(asA, a, project, now);
    await ok(api("/api/projects/assets/setPrice.php", { assetsAssignments: [line], assetsAssignments_customPrice: "20.00" }), "pricing it");
    await expectFinances(asA, a, project, now);
    await ok(api("/api/projects/assets/setPrice.php", { assetsAssignments: [line], assetsAssignments_customPrice: "0" }), "removing its custom price");
    await expectFinances(asA, a, project, now);
  });

  await test.step("moving the dates, or charging by days and weeks, only changes the charged asset", async () => {
    await ok(api("/api/projects/changeProjectDeliverDates.php", {
      projects_id: project, projects_dates_deliver_start: "2036-10-01 09:00:00", projects_dates_deliver_end: "2036-10-07 18:00:00",
    }), "changing the dates");
    now = { ...now, subTotal: 7000, total: 7000, grandTotal: 7000 };
    await expectFinances(asA, a, project, now);
    await ok(api("/api/projects/changeProjectFinanceDurationMaths.php", { projects_id: project, projects_dates_finances_days: 2, projects_dates_finances_weeks: 1 }), "1 week and 2 days");
    now = { ...now, subTotal: 5000, total: 5000, grandTotal: 5000 };
    await expectFinances(asA, a, project, now);
  });

  await test.step("changing the accessory's own rates doesn't change the totals", async () => {
    await ok(api("/api/assets/editAsset.php", { assets_id: accessory, assets_dayRate: "15.00" }), "editing its day rate");
    await expectFinances(asA, a, project, now);
  });

  await test.step("putting it back in the totals charges for it, with its discount, at the current rates", async () => {
    await ok(api("/api/projects/assets/setHidden.php", { assetsAssignments: [line], assetsAssignments_excludedFromTotals: 0 }), "including it");
    // £30.00 a week + 2 days at its own £15.00, half off
    now = { ...now, subTotal: 11000, discounts: 3000, total: 8000, grandTotal: 8000 };
    await expectFinances(asA, a, project, now);
  });

  await test.step("removing a line left out of the totals takes off only its mass and value", async () => {
    await ok(api("/api/projects/assets/setHidden.php", { assetsAssignments: [line], assetsAssignments_excludedFromTotals: 1 }), "leaving it out");
    now = { ...now, subTotal: 5000, discounts: 0, total: 5000, grandTotal: 5000 };
    await expectFinances(asA, a, project, now);
    await ok(api("/api/projects/assets/unassign.php", { assetsAssignments: [line] }), "removing it");
    now = { ...now, value: 10000, mass: 1 };
    await expectFinances(asA, a, project, now);
  });
});

test("the asset list marks hidden lines and lines that aren't charged for", async ({ asA, tenants: { a } }) => {
  const project = await newProject(asA, a, a.users.full.id, { start: "2036-10-10 09:00:00", end: "2036-10-10 18:00:00" });
  const asset = await newAsset(asA, a);
  await ok(assign(asA, a, project, asset), "assigning");
  const line = assignmentOf(project, asset);
  await ok(asA.api("/api/projects/assets/setHidden.php", { instances_id: a.instanceId, assetsAssignments: [line], assetsAssignments_hidden: 1, assetsAssignments_excludedFromTotals: 1 }), "hiding it");
  const data = await asA.api("/api/projects/data.php", { instances_id: a.instanceId, id: project });
  const assignment = Object.values<any>(data.json.response.FINANCIALS.assetsAssigned)[0].assets[0];
  // It counts as nothing, but what it would cost is still known
  expect(assignment).toMatchObject({ assetsAssignments_hidden: 1, assetsAssignments_excludedFromTotals: 1, price: { amount: "0" }, chargeablePrice: { amount: "1000" } });
  const page = await asA.page(`/project/?id=${project}`);
  expect(page.body).toContain(`id="assetRow${line}"`);
  expect(page.body).toContain("assignmentHiddenBadge");
  expect(page.body).toContain("assignmentExcludedBadge");
});

test("hidden lines are left off quotes and invoices but not delivery notes", async ({ asA, tenants: { a } }) => {
  const stamp = Date.now();
  const caseName = `Hidden flight case ${stamp}`;
  const micName = `Shown microphone ${stamp}`;
  const [caseAsset] = await typeWithAssets(asA, a, caseName, 1);
  const [micAsset] = await typeWithAssets(asA, a, micName, 1);
  // One day: each costs £10.00
  const project = await newProject(asA, a, a.users.full.id, { start: "2036-10-20 09:00:00", end: "2036-10-20 18:00:00" });
  await ok(assign(asA, a, project, caseAsset), "assigning the case");
  await ok(assign(asA, a, project, micAsset), "assigning the mic");
  const caseLine = assignmentOf(project, caseAsset);
  const documents = { quote: "Quotation", invoice: "Invoice", deliveryNote: "Delivery Note" } as const;
  const open = async (type: keyof typeof documents) => {
    const page = await asA.page(`/project/projectInvoice.php?id=${project}&type=${type}&finance=1&assets=1&prices=1`);
    expect(page.status, `opening the ${documents[type]}`).toBe(200);
    return page.body;
  };

  for (const type of Object.keys(documents) as (keyof typeof documents)[]) {
    const body = await open(type);
    expect(body, `the ${documents[type]} lists the case before it's hidden`).toContain(jsEscaped(caseName));
    expect(body).toContain('text: "2 Total Items"');
  }

  await ok(asA.api("/api/projects/assets/setHidden.php", { instances_id: a.instanceId, assetsAssignments: [caseLine], assetsAssignments_hidden: 1 }), "hiding the case");
  for (const type of ["quote", "invoice"] as const) {
    const body = await open(type);
    expect(body, `the ${documents[type]} leaves the case off`).not.toContain(jsEscaped(caseName));
    expect(body, `the ${documents[type]} still lists the mic`).toContain(jsEscaped(micName));
    expect(body).toContain('text: "1 Total Item"');
  }
  const deliveryNote = await open("deliveryNote");
  expect(deliveryNote, "the delivery note still lists the case, for picking").toContain(jsEscaped(caseName));
  expect(deliveryNote).toContain('text: "2 Total Items"');
  // Hidden but still charged for, so the totals include it
  expect(await documentSummary(asA, project, "quote")).toMatchObject({ "Grand Total": "£20.00" });

  await ok(asA.api("/api/projects/assets/setHidden.php", { instances_id: a.instanceId, assetsAssignments: [caseLine], assetsAssignments_excludedFromTotals: 1 }), "not charging for the case");
  expect(await documentSummary(asA, project, "quote")).toMatchObject({ "Grand Total": "£10.00" });
  expect(await documentSummary(asA, project, "invoice")).toMatchObject({ "Grand Total Outstanding": "£10.00" });
});

test("hiding lines and leaving them out of the totals from the project's asset list", async ({ page, asA, tenants: { a, password } }) => {
  await cacheCdn(page.context());
  await login(page, a.users.full.email, password);
  const project = await newProject(asA, a, a.users.full.id, { start: "2036-10-25 09:00:00", end: "2036-10-25 18:00:00" });
  const asset = await newAsset(asA, a);
  await ok(assign(asA, a, project, asset), "assigning");
  const line = assignmentOf(project, asset);

  const openAssetList = async () => {
    await page.getByRole("link", { name: "Assets List" }).click();
    await page.locator("#asset-list-tab-thisinstance").click();
    await page.locator("#expandAll").click();
  };
  await page.goto(`/project/?id=${project}`, { waitUntil: "domcontentloaded" });
  await openAssetList();
  await page.locator(`.assetAssignmentCheckbox[data-assetassignmentid="${line}"]`).check();
  await page.locator("#setAssetAssignmentHidden").click();
  await page.locator("#assignmentHidden").selectOption("1");
  await page.locator("#assignmentExcludedFromTotals").selectOption("1");
  await Promise.all([page.waitForEvent("load"), page.getByRole("button", { name: "Save" }).click()]);

  expect(dbQuery("SELECT assetsAssignments_hidden h, assetsAssignments_excludedFromTotals x FROM assetsAssignments WHERE assetsAssignments_id = ?", [line])).toEqual([{ h: 1, x: 1 }]);
  await openAssetList();
  const row = page.locator(`#assetRow${line}`);
  await expect(row.locator(".assignmentHiddenBadge")).toBeVisible();
  await expect(row.locator(".assignmentExcludedBadge")).toHaveAttribute("title", /would otherwise be £10\.00/);
});
