import { cacheCdn } from "../cdn";
import { login } from "../fixtures";
import { bookings, newProject } from "../projects";
import { expect, formData, test } from "../tenants";

/** The assets page can list the search results as a table as well as the default grid of cards */

test.beforeEach(async ({ page, tenants: { a, password } }) => {
  await cacheCdn(page.context());
  await login(page, a.users.full.email, password);
});

test("the layout buttons switch between the grid and the table, and a search keeps the table", async ({ page, tenants: { a } }) => {
  const keyword = `keyword[]=${encodeURIComponent(`${a.marker} asset type`)}`;
  await page.goto(`/assets.php?${keyword}`);
  await expect(page.locator("#assetSearchTable")).toHaveCount(0);
  await expect(page.locator('button[data-view="grid"]')).toHaveClass(/active/);

  await page.locator('button[data-view="table"]').click();
  await page.waitForURL(/view=table/);
  const table = page.locator("#assetSearchTable");
  await expect(table).toBeVisible();
  await expect(page.locator('button[data-view="table"]')).toHaveClass(/active/);
  await expect(table.locator("thead th")).toContainText(["Name", "Category", "Manufacturer", "Mass", "Value", "Day rate", "Week rate"]);
  await expect(table.locator("tbody tr", { hasText: `${a.marker} asset type` })).toHaveCount(1);

  // Searching again from the form stays in the table
  await page.locator("#assetSearchForm button[type=submit]").click();
  await page.waitForLoadState("load");
  expect(new URL(page.url()).searchParams.get("view")).toBe("table");
  await expect(page.locator("#assetSearchTable")).toBeVisible();

  await page.locator('button[data-view="grid"]').click();
  await page.waitForURL(/view=grid/);
  await expect(page.locator("#assetSearchTable")).toHaveCount(0);
});

test("an asset can be added to and removed from a project from the table", async ({ page, asA, tenants: { a } }) => {
  const typeName = `Table view type ${Date.now()}`;
  const type = await asA.api("/api/assets/newAssetType.php", { instances_id: a.instanceId, formData: formData({
    manufacturers_id: a.manufacturerId, assetCategories_id: a.categoryId, assetTypes_name: typeName, assetTypes_dayRate: "3.00", assetTypes_weekRate: "9.00",
  }) });
  const asset = (await asA.api("/api/assets/newAssetFromType.php", { instances_id: a.instanceId, formData: formData({ assetTypes_id: type.json.response.assetTypes_id }) })).json.response.assets_id as number;
  const project = await newProject(asA, a, a.users.full.id, { start: "2036-08-01 09:00:00", end: "2036-08-02 18:00:00" });

  await page.goto(`/assets.php?project=${project}&view=table&keyword[]=${encodeURIComponent(typeName)}`);
  const row = page.locator("#assetSearchTable tbody tr", { hasText: typeName });
  await expect(row).toContainText("£3.00");
  await expect(row).toContainText("£9.00");
  await expect(row).toContainText("1 (1 available)");

  await row.locator(`.addToBasketAssetButton[data-assetid="${asset}"]`).click();
  await expect(row.locator(`.removeFromBasketAssetButton[data-assetid="${asset}"]`)).toBeVisible();
  expect(bookings(asset)).toEqual([project]);

  await row.locator(`.removeFromBasketAssetButton[data-assetid="${asset}"]`).click();
  await page.locator(".bootbox .btn-danger").click();
  await expect(row.locator(`.addToBasketAssetButton[data-assetid="${asset}"]`)).toBeVisible();
  await expect.poll(() => bookings(asset)).toEqual([]);
});
