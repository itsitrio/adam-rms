import { cacheCdn } from "../cdn";
import { login } from "../fixtures";
import { assign, bookings, newAsset, newProject } from "../projects";
import { dbQuery, expect, formData, test } from "../tenants";

/**
 * The everyday jobs, done through the pages as a user would: clicking the buttons and filling in the forms.
 * The API tests check the endpoints; these check the pages still call them with the right things. Records are
 * set up through the API where the page under test isn't the one that creates them.
 */

test.beforeEach(async ({ page, tenants: { a, password } }) => {
  await cacheCdn(page.context());
  await login(page, a.users.full.email, password);
});

test("creating a project from the New Project page", async ({ page, tenants: { a } }) => {
  const name = `Journey project ${Date.now()}`;
  await page.goto("/project/new.php");
  await page.locator("#projects_name").fill(name);
  await page.locator("#projects_description").fill("Made by clicking through");
  await page.locator("#saveButton").click();

  await page.waitForURL(/\/project\/\?id=\d+/);
  const id = Number(new URL(page.url()).searchParams.get("id"));
  await expect(page.locator('a[href="#details-view"]')).toHaveText(name);
  expect(dbQuery("SELECT projects_name, projects_description, projects_manager, instances_id FROM projects WHERE projects_id = ?", [id])).toEqual([
    { projects_name: name, projects_description: "Made by clicking through", projects_manager: a.users.full.id, instances_id: a.instanceId },
  ]);
});

test("adding an asset to a project from the assets page", async ({ page, asA, tenants: { a } }) => {
  // A type with only this asset, so the page shows the asset's own "Add to project" button
  const typeName = `Journey type ${Date.now()}`;
  const type = await asA.api("/api/assets/newAssetType.php", { instances_id: a.instanceId, formData: formData({
    manufacturers_id: a.manufacturerId, assetCategories_id: a.categoryId, assetTypes_name: typeName, assetTypes_dayRate: "1.00",
  }) });
  const asset = (await asA.api("/api/assets/newAssetFromType.php", { instances_id: a.instanceId, formData: formData({ assetTypes_id: type.json.response.assetTypes_id }) })).json.response.assets_id as number;
  const project = await newProject(asA, a, a.users.full.id, { start: "2036-07-01 09:00:00", end: "2036-07-02 18:00:00" });

  await page.goto(`/assets.php?project=${project}&keyword[]=${encodeURIComponent(typeName)}`);
  await page.locator(`.addToBasketAssetButton[data-assetid="${asset}"]`).click();
  await expect(page.locator(`.removeFromBasketAssetButton[data-assetid="${asset}"]`)).toBeVisible();
  expect(bookings(asset)).toEqual([project]);

  // ...and it's on the project's asset list
  await page.goto(`/project/?id=${project}`);
  await page.locator('a[href="#assets-view"]').click();
  await expect(page.locator("#assets-view")).toContainText(typeName);
});

test("recording a payment from the project's Finance tab", async ({ page, asA, tenants: { a } }) => {
  const project = await newProject(asA, a, a.users.full.id, { start: "2036-07-03 09:00:00", end: "2036-07-04 18:00:00" });
  await page.goto(`/project/?id=${project}`);
  await page.locator('a[href="#payments-view"]').click();
  await page.locator('button.newPayment[data-type="1"]').click();
  const modal = page.locator("#newPaymentModal");
  await expect(modal).toBeVisible();
  await modal.locator('input[name="payments_reference"]').fill("JOURNEY-REF");
  await modal.locator('input[name="payments_amount"]').fill("12.50");
  await page.locator("#newPaymentModal-button").click();

  await page.waitForLoadState("load");
  await expect.poll(() => dbQuery("SELECT payments_type, payments_amount, payments_reference FROM payments WHERE projects_id = ? AND payments_deleted = 0", [project]))
    .toEqual([{ payments_type: 1, payments_amount: 1250, payments_reference: "JOURNEY-REF" }]);
  await page.locator('a[href="#payments-view"]').click();
  await expect(page.locator("#payments-view")).toContainText("£12.50");
});

test("editing a staff line from the project's Finance tab", async ({ page, asA, tenants: { a } }) => {
  const project = await newProject(asA, a, a.users.full.id, { start: "2036-07-05 09:00:00", end: "2036-07-06 18:00:00" });
  await asA.api("/api/projects/newPayment.php", { instances_id: a.instanceId, formData: formData({
    projects_id: project, payments_type: 4, payments_quantity: 5, payments_amount: "35.00", payments_comment: "DJ Hire", payments_date: "2036-07-05",
  }) });
  await page.goto(`/project/?id=${project}`);
  await page.locator('a[href="#payments-view"]').click();
  await page.locator("#payments-view .editPaymentButton").click();
  const modal = page.locator("#newPaymentModal");
  await expect(modal).toBeVisible();
  // The form opens with the line as it is
  await expect(modal.locator('input[name="payments_quantity"]')).toHaveValue("5");
  await expect(modal.locator('input[name="payments_amount"]')).toHaveValue("35.00");
  await expect(modal.locator('textarea[name="payments_comment"]')).toHaveValue("DJ Hire");
  await modal.locator('input[name="payments_quantity"]').fill("4");
  await modal.locator('input[name="payments_amount"]').fill("40.00");
  await page.locator("#newPaymentModal-button").click();

  await page.waitForLoadState("load");
  await expect.poll(() => dbQuery("SELECT payments_type, payments_quantity, payments_amount, payments_comment FROM payments WHERE projects_id = ? AND payments_deleted = 0", [project]))
    .toEqual([{ payments_type: 4, payments_quantity: 4, payments_amount: 4000, payments_comment: "DJ Hire" }]);
  await page.locator('a[href="#payments-view"]').click();
  await expect(page.locator("#payments-view")).toContainText("£160.00");
});

test("updating a project to the current rates from its assets list or its Finance tab", async ({ page, asA, tenants: { a } }) => {
  const type = { manufacturers_id: a.manufacturerId, assetCategories_id: a.categoryId, assetTypes_name: `Journey rates type ${Date.now()}` };
  const created = await asA.api("/api/assets/newAssetType.php", { instances_id: a.instanceId, formData: formData({ ...type, assetTypes_dayRate: "1.00", assetTypes_weekRate: "5.00" }) });
  const typeId: number = created.json.response.assetTypes_id;
  const asset = (await asA.api("/api/assets/newAssetFromType.php", { instances_id: a.instanceId, formData: formData({ assetTypes_id: typeId }) })).json.response.assets_id as number;
  const tag = dbQuery<{ tag: string }>("SELECT assets_tag tag FROM assets WHERE assets_id = ?", [asset])[0].tag;
  const project = await newProject(asA, a, a.users.full.id, { start: "2036-07-05 09:00:00", end: "2036-07-06 18:00:00" });
  expect((await assign(asA, a, project, asset)).json).toMatchObject({ result: true });
  await asA.api("/api/assets/editAssetType.php", { instances_id: a.instanceId, formData: formData({ ...type, assetTypes_id: typeId, assetTypes_dayRate: "2.00", assetTypes_weekRate: "5.00" }) });
  const dialog = page.locator(".bootbox.modal");
  // What changes, line by line, and the totals before and after: two days at the rate it was added at, and at today's
  const expectSummary = async () => {
    await expect(dialog.locator(".refreshRatesLines tbody tr td")).toHaveText([`${tag} ${type.assetTypes_name}`, "£1.00 → £2.00", "£5.00", "£2.00 → £4.00"]);
    await expect(dialog.locator(".refreshRatesTotals tbody tr")).toHaveText([/Equipment subtotal\s*£2\.00\s*£4\.00/, /Discounts\s*£0\.00\s*£0\.00/, /Equipment total\s*£2\.00\s*£4\.00/]);
  };

  await page.goto(`/project/?id=${project}`);
  await page.locator('a[href="#assets-view"]').click();
  await page.locator("#assets-view .refreshProjectAssetRates").click();
  await expectSummary();
  await dialog.locator("button.bootbox-cancel").click();
  await expect(dialog).toHaveCount(0);
  expect(dbQuery("SELECT assetsAssignments_dayRate dayRate FROM assetsAssignments WHERE projects_id = ? AND assets_id = ?", [project, asset]), "cancelling changes nothing")
    .toEqual([{ dayRate: 100 }]);

  await page.locator('a[href="#payments-view"]').click();
  await page.locator("#payments-view .refreshProjectAssetRates").click();
  await expectSummary();
  await dialog.locator("button.bootbox-accept").click();
  await expect.poll(() => dbQuery("SELECT assetsAssignments_dayRate dayRate FROM assetsAssignments WHERE projects_id = ? AND assets_id = ?", [project, asset]))
    .toEqual([{ dayRate: 200 }]);
});

test("dispatching an asset on the Asset Dispatch board, with the arrows and by typing its tag", async ({ page, asA, tenants: { a } }) => {
  // Two statuses of our own, after the business's others, so the "»" arrow on the first goes to the second
  const statuses = ["Journey out", "Journey back"].map((name, i) => {
    dbQuery("INSERT INTO assetsAssignmentsStatus (instances_id, assetsAssignmentsStatus_name, assetsAssignmentsStatus_order, assetsAssignmentsStatus_deleted) VALUES (?, ?, ?, 0)",
      [a.instanceId, name, 900 + i]);
    return dbQuery<{ id: number }>("SELECT MAX(assetsAssignmentsStatus_id) id FROM assetsAssignmentsStatus WHERE instances_id = ?", [a.instanceId])[0].id;
  });
  try {
    const asset = await newAsset(asA, a);
    const [{ tag }] = dbQuery<{ tag: string }>("SELECT assets_tag tag FROM assets WHERE assets_id = ?", [asset]);
    const project = await newProject(asA, a, a.users.full.id, { start: "2036-07-05 09:00:00", end: "2036-07-06 18:00:00" });
    await assign(asA, a, project, asset);
    const [{ id: assignment }] = dbQuery<{ id: number }>("SELECT assetsAssignments_id id FROM assetsAssignments WHERE projects_id = ?", [project]);
    await asA.api("/api/projects/assets/setStatus.php", { instances_id: a.instanceId, assetsAssignments_id: assignment, assetsAssignments_status: statuses[0] });
    const status = () => dbQuery<{ s: number }>("SELECT assetsAssignmentsStatus_id s FROM assetsAssignments WHERE assetsAssignments_id = ?", [assignment])[0].s;

    await page.goto(`/project/?id=${project}`);
    await page.locator('a[href="#assets-board"]').click();
    const card = page.locator(`.assetDispatchAssetList-card[data-asset="${asset}"]`);
    await expect(page.locator(`.assetDispatchAssetList[data-statusid="${statuses[0]}"]`)).toContainText(tag);
    await card.locator(".assetDispatchAssetList-statusNextButton").click();
    await expect(page.locator(`.assetDispatchAssetList[data-statusid="${statuses[1]}"]`)).toContainText(tag);
    await expect.poll(status).toBe(statuses[1]);

    await page.locator("#navItemQuickDispatch button").click();
    await page.locator(".assetStatusSelectorQuickDispatch:visible").selectOption(String(statuses[0]));
    await page.locator("#assetTagQuickDispatch").fill(tag);
    await page.locator("#goQuickDispatch").click();
    await expect.poll(status).toBe(statuses[0]);
  } finally {
    dbQuery(`UPDATE assetsAssignmentsStatus SET assetsAssignmentsStatus_deleted = 1 WHERE assetsAssignmentsStatus_id IN (${statuses.join(",")})`);
  }
});

test("reporting a fault on an asset from the New Maintenance Job page", async ({ page, asA, tenants: { a } }) => {
  const asset = await newAsset(asA, a);
  const [{ tag }] = dbQuery<{ tag: string }>("SELECT assets_tag tag FROM assets WHERE assets_id = ?", [asset]);
  await page.goto("/maintenance/job.php");
  await page.locator('input[name="maintenanceJobs_title"]').fill("Journey fault");
  await page.locator('textarea[name="maintenanceJobs_faultDescription"]').fill("It rattles");
  // The asset picker searches as you type (api/maintenance/searchAsset.php)
  await page.locator("#addAssetModal-assets + .select2 .select2-selection").click();
  await page.locator(".select2-search__field").fill(tag);
  await page.locator(".select2-results__option", { hasText: tag }).click();
  await page.getByRole("button", { name: "Create" }).click();

  await page.waitForURL(/\/maintenance\/job\.php\?id=\d+/);
  const id = Number(new URL(page.url()).searchParams.get("id"));
  expect(dbQuery("SELECT maintenanceJobs_title, maintenanceJobs_faultDescription, maintenanceJobs_assets, instances_id FROM maintenanceJobs WHERE maintenanceJobs_id = ?", [id])).toEqual([
    { maintenanceJobs_title: "Journey fault", maintenanceJobs_faultDescription: "It rattles", maintenanceJobs_assets: String(asset), instances_id: a.instanceId },
  ]);
  await expect(page.locator("body")).toContainText("Journey fault");
});

test("changing the business address on Business Settings without a website", async ({ page, tenants: { a } }) => {
  // A business doesn't need a website, so leaving it empty mustn't stop the form saving
  const address = () => dbQuery<{ address: string; website: string | null }>("SELECT instances_address address, instances_website website FROM instances WHERE instances_id = ?", [a.instanceId])[0];
  expect(address().website).toBeNull();
  try {
    await page.goto("/instances/settings.php");
    await page.locator('#basicSettings textarea[name="instances_address"]').fill("1 New Street");
    await page.locator("#basicSettings").getByRole("button", { name: "Save" }).click();
    await expect.poll(address).toEqual({ address: "1 New Street", website: null });
  } finally {
    dbQuery("UPDATE instances SET instances_address = ? WHERE instances_id = ?", [`${a.marker} address`, a.instanceId]);
  }
});
