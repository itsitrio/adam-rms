import { assign, newAsset, newProject, pipelineStatus } from "../projects";
import { dbQuery, expect, formData, test } from "../tenants";

/**
 * An asset's page shows the revenue it has brought in: what it's priced at on each project it's booked on. Only
 * projects whose status is at the Won stage of the sales pipeline count - split into past projects (revenue to date)
 * and upcoming/current ones (booked). Leads and Opportunities are shown as the pipeline, and Lost and Cancelled
 * projects don't count at all, whether or not their status releases their assets. It also lists the asset's
 * bookings as upcoming and past.
 */

const PAST = { start: "2020-03-01 09:00:00", end: "2020-03-03 18:00:00" }; // 3 days at £10.00
const PAST_LOST = { start: "2020-05-01 09:00:00", end: "2020-05-03 18:00:00" };
const FUTURE = { start: "2036-09-01 09:00:00", end: "2036-09-03 18:00:00" };
const FUTURE_LEAD = { start: "2036-10-01 09:00:00", end: "2036-10-03 18:00:00" };

function revenue(body: string, id: string) {
  return body.match(new RegExp(`id="${id}">([^<]*)<`))?.[1];
}

test("an asset's revenue counts Won projects only, split into past, booked and pipeline", async ({ asA, tenants: { a } }) => {
  const won = pipelineStatus(a, "WON");
  const lost = pipelineStatus(a, "LOST"); // Doesn't release assets, so only its pipeline stage keeps it out
  const opportunity = pipelineStatus(a, "OPPORTUNITY");

  const asset = await newAsset(asA, a);
  const pastWon = await newProject(asA, a, a.users.full.id, PAST);
  const pastLost = await newProject(asA, a, a.users.full.id, PAST_LOST);
  const futureWon = await newProject(asA, a, a.users.full.id, FUTURE);
  const futureOpportunity = await newProject(asA, a, a.users.full.id, FUTURE_LEAD);
  for (const project of [pastWon, pastLost, futureWon, futureOpportunity]) expect((await assign(asA, a, project, asset)).json).toMatchObject({ result: true });
  for (const [project, status] of [[pastWon, won], [pastLost, lost], [futureWon, won], [futureOpportunity, opportunity]]) {
    expect((await asA.api("/api/projects/changeStatus.php", { instances_id: a.instanceId, projects_id: project, projectsStatuses_id: status })).json)
      .toMatchObject({ result: true });
  }
  // Half off the upcoming won booking
  dbQuery("UPDATE assetsAssignments SET assetsAssignments_discount = 50 WHERE projects_id = ? AND assets_id = ?", [futureWon, asset]);

  const page = await asA.page(`/asset.php?id=${a.assetTypeId}&asset=${asset}`);
  expect(revenue(page.body, "assetRevenueEarned")).toBe("£30.00");
  expect(revenue(page.body, "assetRevenueBooked")).toBe("£15.00");
  expect(revenue(page.body, "assetRevenuePipeline")).toBe("£30.00");

  // The bookings list has every project, the lost one struck through and the opportunity marked as not yet won
  expect(page.body).toContain('id="bookingsListAsset"');
  for (const project of [pastWon, pastLost, futureWon, futureOpportunity]) expect(page.body).toContain(`/project/?id=${project}"`);
  expect(page.body).toContain('<s title="Lost - not counted as revenue">£30.00</s>');
  expect(page.body).toContain('<i title="Opportunity - not yet won, so counted in the pipeline rather than revenue">£30.00</i>');

  // And the asset type's list of assets has a revenue column
  const typePage = await asA.page(`/asset.php?id=${a.assetTypeId}`);
  expect(typePage.body).toContain(">Revenue</th>");
});

test("a project status's pipeline stage can be set, and must be one of the five stages", async ({ asA, tenants: { a } }) => {
  const stageOf = (name: string) => dbQuery<{ stage: string }>(
    "SELECT projectsStatuses_pipelineStage stage FROM projectsStatuses WHERE instances_id = ? AND projectsStatuses_name = ? AND projectsStatuses_deleted = 0",
    [a.instanceId, name],
  )[0]?.stage;
  const name = `Stage test ${Date.now()}`;
  const fields = { projectsStatuses_name: name, projectsStatuses_description: "e2e", projectsStatuses_foregroundColour: "#000000", projectsStatuses_backgroundColour: "#ffffff", projectsStatuses_rank: 50 };

  expect((await asA.api("/api/instances/projectStatus/new.php", { formData: formData({ ...fields, projectsStatuses_pipelineStage: "MAYBE" }) })).json)
    .toMatchObject({ result: false });
  expect(stageOf(name)).toBeUndefined();

  expect((await asA.api("/api/instances/projectStatus/new.php", { formData: formData({ ...fields, projectsStatuses_pipelineStage: "OPPORTUNITY" }) })).json)
    .toMatchObject({ result: true });
  expect(stageOf(name)).toBe("OPPORTUNITY");
  const [{ id }] = dbQuery<{ id: number }>("SELECT projectsStatuses_id id FROM projectsStatuses WHERE instances_id = ? AND projectsStatuses_name = ?", [a.instanceId, name]);

  expect((await asA.api("/api/instances/projectStatus/edit.php", { formData: formData({ projectsStatuses_id: id, projectsStatuses_pipelineStage: "NOPE" }) })).json)
    .toMatchObject({ result: false });
  expect(stageOf(name)).toBe("OPPORTUNITY");
  expect((await asA.api("/api/instances/projectStatus/edit.php", { formData: formData({ projectsStatuses_id: id, projectsStatuses_pipelineStage: "WON" }) })).json)
    .toMatchObject({ result: true });
  expect(stageOf(name)).toBe("WON");

  // A status made without saying which stage it's at starts as a Lead, so it doesn't count as revenue
  const unstaged = `${name} unstaged`;
  await asA.api("/api/instances/projectStatus/new.php", { formData: formData({ ...fields, projectsStatuses_name: unstaged }) });
  expect(stageOf(unstaged)).toBe("LEAD");

  // The settings page shows each status's stage
  expect((await asA.page("/instances/projectStatuses.php")).body).toContain('name="projectsStatuses_pipelineStage"');
});

test("a new business's default statuses are given their pipeline stages", async () => {
  // The migration gives statuses with the default names their stage; new businesses get them from instances/new.php.
  // Matched on description too, as other tests make statuses of their own.
  const defaults: [string, string, string][] = [
    ["Quote Sent", "Waiting for client confirmation", "OPPORTUNITY"],
    ["Confirmed", "Booked in with client", "WON"],
    ["Closed", "Pending move to Archive", "WON"],
    ["Cancelled", "Project Cancelled", "CANCELLED"],
    ["Lead Lost", "Project Cancelled", "LOST"],
  ];
  for (const [name, description, stage] of defaults) {
    const rows = dbQuery<{ stage: string }>(
      "SELECT projectsStatuses_pipelineStage stage FROM projectsStatuses WHERE projectsStatuses_name = ? AND projectsStatuses_description = ?",
      [name, description],
    );
    expect(rows.length, `a default "${name}" status`).toBeGreaterThan(0);
    for (const row of rows) expect(row.stage, name).toBe(stage);
  }
});
