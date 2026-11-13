import { assign, bookings, type Dates, expectFinances, newProject } from "../projects";
import { dbQuery, expect, formData, type Session, test, type Tenant } from "../tenants";

/**
 * Unserialized assets are one row holding a quantity of interchangeable units (assets_quantity), and an
 * assignment takes a number of those units (assetsAssignments_quantity). Every test makes its own assets and
 * projects, so units booked by other tests can't get in the way.
 */

const DATES: Dates = { start: "2037-04-06 09:00:00", end: "2037-04-08 18:00:00" }; // 3 days
const OVERLAPPING: Dates = { start: "2037-04-07 09:00:00", end: "2037-04-10 18:00:00" };
const LATER: Dates = { start: "2037-05-01 09:00:00", end: "2037-05-03 18:00:00" };

async function newUnserializedAsset(session: Session, t: Tenant, quantity: number) {
  const created = await session.api("/api/assets/newAssetFromType.php", {
    instances_id: t.instanceId, formData: formData({ assetTypes_id: t.assetTypeId, assets_unserialized: 1, assets_quantity: quantity }),
  });
  expect(created.json, "creating the asset").toMatchObject({ result: true });
  return created.json.response.assets_id as number;
}

async function assignUnits(session: Session, t: Tenant, projectId: number, assetId: number, quantity: number) {
  const response = await session.api("/api/projects/assets/assign.php", {
    instances_id: t.instanceId, projects_id: projectId, assets_id: assetId, assetsAssignments_quantity: quantity,
  });
  expect(response.json, `assigning ${quantity} units`).toMatchObject({ result: true });
}

function setQuantityHeld(session: Session, t: Tenant, assetId: number, quantity: number) {
  return session.api("/api/assets/editAsset.php", { instances_id: t.instanceId, formData: formData({ assets_id: assetId, assets_quantity: quantity }) });
}

function quantityHeld(assetId: number) {
  return dbQuery<{ n: number }>("SELECT assets_quantity n FROM assets WHERE assets_id = ?", [assetId])[0].n;
}

function assignmentOf(projectId: number, assetId: number) {
  return dbQuery<{ id: number }>(
    "SELECT assetsAssignments_id id FROM assetsAssignments WHERE projects_id = ? AND assets_id = ? AND assetsAssignments_deleted = 0",
    [projectId, assetId],
  )[0].id;
}

test.describe("reducing the quantity held", () => {
  test("is refused below what projects on overlapping dates hold between them", async ({ asA, tenants: { a } }) => {
    const asset = await newUnserializedAsset(asA, a, 6);
    await assignUnits(asA, a, await newProject(asA, a, a.users.full.id, DATES), asset, 3);
    await assignUnits(asA, a, await newProject(asA, a, a.users.full.id, OVERLAPPING), asset, 3);

    // Both projects need their 3 units at once on the 7th and 8th, so 6 have to stay in stock
    await expect(setQuantityHeld(asA, a, asset, 4)).resolves.toMatchObject({ json: { result: false } });
    expect(quantityHeld(asset)).toBe(6);
  });

  test("is allowed down to what projects on separate dates each hold", async ({ asA, tenants: { a } }) => {
    const asset = await newUnserializedAsset(asA, a, 6);
    await assignUnits(asA, a, await newProject(asA, a, a.users.full.id, DATES), asset, 3);
    await assignUnits(asA, a, await newProject(asA, a, a.users.full.id, LATER), asset, 3);

    // The same 3 units can go out on one job and then the next
    await expect(setQuantityHeld(asA, a, asset, 3)).resolves.toMatchObject({ json: { result: true } });
    expect(quantityHeld(asset)).toBe(3);
    await expect(setQuantityHeld(asA, a, asset, 2)).resolves.toMatchObject({ json: { result: false } });
    expect(quantityHeld(asset)).toBe(3);
  });
});

test.describe("swapping an unserialized asset", () => {
  test("won't swap in an asset the project already has its own assignment of", async ({ asA, tenants: { a } }) => {
    const first = await newUnserializedAsset(asA, a, 10);
    const second = await newUnserializedAsset(asA, a, 10);
    const project = await newProject(asA, a, a.users.full.id, DATES);
    await assignUnits(asA, a, project, first, 2);
    await assignUnits(asA, a, project, second, 3);

    // Each asset gets one assignment per project, so this would leave the project with two of the second asset
    await expect(asA.api("/api/projects/assets/swap.php", { instances_id: a.instanceId, assetsAssignments_id: assignmentOf(project, first), assets_id: second }))
      .resolves.toMatchObject({ json: { result: false } });
    expect(bookings(first)).toEqual([project]);
    expect(bookings(second)).toEqual([project]);
  });

  test("moves every unit the assignment took onto the new asset, and the project's totals with them", async ({ asA, tenants: { a } }) => {
    const booked = await newUnserializedAsset(asA, a, 10);
    const pricier = await newUnserializedAsset(asA, a, 10);
    await expect(asA.api("/api/assets/editAsset.php", {
      instances_id: a.instanceId, formData: formData({ assets_id: pricier, assets_dayRate: "20.00", assets_value: "250.00", assets_mass: 3 }),
    })).resolves.toMatchObject({ json: { result: true } });
    const project = await newProject(asA, a, a.users.full.id, DATES);
    await assignUnits(asA, a, project, booked, 4);
    const none = { discounts: 0, received: 0, sales: 0, subHire: 0, staff: 0 };
    // 4 units for 3 days at £10.00, worth £100.00 and weighing 1kg each
    await expectFinances(asA, a, project, { ...none, subTotal: 12000, total: 12000, grandTotal: 12000, value: 40000, mass: 4 });

    await expect(asA.api("/api/projects/assets/swap.php", { instances_id: a.instanceId, assetsAssignments_id: assignmentOf(project, booked), assets_id: pricier }))
      .resolves.toMatchObject({ json: { result: true } });
    // 4 units for 3 days at £20.00, worth £250.00 and weighing 3kg each
    await expectFinances(asA, a, project, { ...none, subTotal: 24000, total: 24000, grandTotal: 24000, value: 100000, mass: 12 });
  });
});

test("a serialized asset can still only be assigned once", async ({ asA, tenants: { a } }) => {
  const created = await asA.api("/api/assets/newAssetFromType.php", { instances_id: a.instanceId, formData: formData({ assetTypes_id: a.assetTypeId }) });
  const asset = created.json.response.assets_id as number;
  const project = await newProject(asA, a, a.users.full.id, DATES);
  await expect(assign(asA, a, project, asset)).resolves.toMatchObject({ json: { result: true } });
  await expect(assign(asA, a, await newProject(asA, a, a.users.full.id, OVERLAPPING), asset)).resolves.toMatchObject({ json: { result: false } });
});
