import { assign, newAsset, newProject, releasedStatus } from "../projects";
import { dbQuery, expect, test } from "../tenants";

/**
 * An asset's page shows the revenue it has brought in: what it's priced at on each project it's booked on,
 * split into past projects (revenue to date) and upcoming/current ones (booked). Projects with a status that
 * releases their assets (lost or cancelled) don't count. It also lists the asset's bookings as upcoming and past.
 */

const PAST = { start: "2020-03-01 09:00:00", end: "2020-03-03 18:00:00" }; // 3 days at £10.00
const PAST_LOST = { start: "2020-05-01 09:00:00", end: "2020-05-03 18:00:00" };
const FUTURE = { start: "2036-09-01 09:00:00", end: "2036-09-03 18:00:00" };

function revenue(body: string, id: string) {
  return body.match(new RegExp(`id="${id}">([^<]*)<`))?.[1];
}

test("an asset's revenue counts won projects only, split into past and booked", async ({ asA, tenants: { a } }) => {
  const asset = await newAsset(asA, a);
  const pastWon = await newProject(asA, a, a.users.full.id, PAST);
  const pastLost = await newProject(asA, a, a.users.full.id, PAST_LOST);
  const future = await newProject(asA, a, a.users.full.id, FUTURE);
  for (const project of [pastWon, pastLost, future]) expect((await assign(asA, a, project, asset)).json).toMatchObject({ result: true });
  // Half off the upcoming booking
  dbQuery("UPDATE assetsAssignments SET assetsAssignments_discount = 50 WHERE projects_id = ? AND assets_id = ?", [future, asset]);
  const lost = releasedStatus(a);
  expect((await asA.api("/api/projects/changeStatus.php", { instances_id: a.instanceId, projects_id: pastLost, projectsStatuses_id: lost })).json)
    .toMatchObject({ result: true });

  const page = await asA.page(`/asset.php?id=${a.assetTypeId}&asset=${asset}`);
  expect(revenue(page.body, "assetRevenueEarned")).toBe("£30.00");
  expect(revenue(page.body, "assetRevenueBooked")).toBe("£15.00");

  // The bookings list has every project, the lost one struck through
  expect(page.body).toContain('id="bookingsListAsset"');
  for (const project of [pastWon, pastLost, future]) expect(page.body).toContain(`/project/?id=${project}"`);
  expect(page.body).toMatch(/<s title="Lost\/cancelled - not counted as revenue">£30.00<\/s>/);

  // And the asset type's list of assets has a revenue column
  const typePage = await asA.page(`/asset.php?id=${a.assetTypeId}`);
  expect(typePage.body).toContain("£30.00");
});
