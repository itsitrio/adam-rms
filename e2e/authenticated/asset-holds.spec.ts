import { cacheCdn } from "../cdn";
import { login } from "../fixtures";
import { assign, newAsset, newProject } from "../projects";
import { dbQuery, expect, formData, test, type Session, type Tenant } from "../tenants";

/**
 * Holds on assets. A hold has a rule - allow, warn or block - for adding the asset to a project (assign) and for
 * sending it out (dispatch: giving its assignment a status whose effect is that the gear leaves the warehouse). A hold
 * can come from a maintenance job or be put on the asset directly, and a block can be signed off by whoever is sending
 * the asset, unless the hold says it can't be.
 */

let n = 0;
const dates = () => { n += 1; const d = String(n).padStart(2, "0"); return { start: `2037-03-${d} 09:00:00`, end: `2037-03-${d} 18:00:00` }; };

async function statusFor(t: Tenant, name: string, effect: string | null, order = 900) {
  dbQuery("INSERT INTO assetsAssignmentsStatus (instances_id, assetsAssignmentsStatus_name, assetsAssignmentsStatus_order, assetsAssignmentsStatus_deleted, assetsAssignmentsStatus_effect) VALUES (?, ?, ?, 0, ?)", [t.instanceId, name, order, effect]);
  return dbQuery<{ id: number }>("SELECT MAX(assetsAssignmentsStatus_id) id FROM assetsAssignmentsStatus WHERE instances_id = ?", [t.instanceId])[0].id;
}

/** Puts `assetId` on hold with the given rules, as the business's user */
async function hold(asA: Session, t: Tenant, assetId: number, fields: { assign: string; dispatch: string; overridable: number; reason?: string }) {
  const saved = await asA.api("/api/assets/holds/save.php", {
    instances_id: t.instanceId, assets_id: assetId, assetHolds_assignMode: fields.assign, assetHolds_dispatchMode: fields.dispatch,
    assetHolds_overridable: fields.overridable, assetHolds_reason: fields.reason ?? "Cracked case",
  });
  expect(saved.json, "putting the asset on hold").toMatchObject({ result: true });
  return saved.json.response.assetHolds_id as number;
}

const assignmentOf = (project: number, asset: number) =>
  dbQuery<{ id: number; status: number | null }>("SELECT assetsAssignments_id id, assetsAssignmentsStatus_id status FROM assetsAssignments WHERE projects_id = ? AND assets_id = ?", [project, asset])[0];

test.describe("holds on assets", () => {
  let out: number, back: number, storage: number;
  test.beforeEach(async ({ tenants: { a } }) => {
    // In the business's order: stored, then the two that leave the warehouse
    storage = await statusFor(a, "Hold test stored", null, 905);
    out = await statusFor(a, "Hold test out", "LEAVES_WAREHOUSE", 910);
    back = await statusFor(a, "Hold test out again", "LEAVES_WAREHOUSE", 911);
  });
  test.afterEach(async () => {
    dbQuery(`UPDATE assetsAssignmentsStatus SET assetsAssignmentsStatus_deleted = 1 WHERE assetsAssignmentsStatus_id IN (${out}, ${back}, ${storage})`);
  });

  /** A project with a new asset assigned to it, optionally putting the asset on hold before it's assigned */
  async function setUp(asA: Session, a: Tenant, before?: { assign: string; dispatch: string; overridable: number }) {
    const asset = await newAsset(asA, a);
    const project = await newProject(asA, a, a.users.full.id, dates());
    if (before) await hold(asA, a, asset, before);
    return { asset, project };
  }
  const setStatus = (asA: Session, a: Tenant, project: number, asset: number, status: number, extra: Record<string, string | number> = {}) =>
    asA.api("/api/projects/assets/setStatus.php", { instances_id: a.instanceId, assetsAssignments_id: assignmentOf(project, asset).id, assetsAssignments_status: status, projects_id: project, ...extra });

  test("can't send out: it can be added to a project, but not sent out, even signed off", async ({ asA, tenants: { a } }) => {
    const { asset, project } = await setUp(asA, a, { assign: "ALLOW", dispatch: "BLOCK", overridable: 0 });
    expect((await assign(asA, a, project, asset)).json).toMatchObject({ result: true });
    for (const extra of [{}, { holdOverride: 1 }]) {
      expect((await setStatus(asA, a, project, asset, out, extra)).json).toMatchObject({ result: false, error: { code: "HOLD-BLOCKED" } });
    }
    expect(assignmentOf(project, asset).status).not.toBe(out);
    // A status that doesn't take it out of the warehouse is fine
    expect((await setStatus(asA, a, project, asset, storage)).json).toMatchObject({ result: true });
  });

  test("can send out, but don't book further: it can be sent out if it's already booked, but not added to another project", async ({ asA, tenants: { a } }) => {
    const { asset, project } = await setUp(asA, a);
    expect((await assign(asA, a, project, asset)).json).toMatchObject({ result: true });
    await hold(asA, a, asset, { assign: "BLOCK", dispatch: "ALLOW", overridable: 1 });
    const other = await newProject(asA, a, a.users.full.id, dates());
    expect((await assign(asA, a, other, asset)).json).toMatchObject({ result: false });
    expect(assignmentOf(other, asset)).toBeUndefined();
    expect((await setStatus(asA, a, project, asset, out)).json).toMatchObject({ result: true });
    expect(assignmentOf(project, asset).status).toBe(out);
  });

  test("warn on assigning, block on sending: it's flagged when it's added, and can't be sent out unless signed off", async ({ asA, tenants: { a } }) => {
    const { asset, project } = await setUp(asA, a, { assign: "WARN", dispatch: "BLOCK", overridable: 1 });
    expect((await assign(asA, a, project, asset)).json).toMatchObject({ result: true }); // A warning doesn't stop it
    expect((await asA.page(`/project/?id=${project}`)).body).toContain("fa-flag");
    expect((await setStatus(asA, a, project, asset, out)).json).toMatchObject({ result: false, error: { code: "HOLD-BLOCKED-OVERRIDABLE" } });
    expect(assignmentOf(project, asset).status).not.toBe(out);
    // The packer has tested it and signs it off
    expect((await setStatus(asA, a, project, asset, out, { holdOverride: 1 })).json).toMatchObject({ result: true });
    expect(assignmentOf(project, asset).status).toBe(out);
    expect(dbQuery("SELECT auditLog_actionData d FROM auditLog WHERE auditLog_actionType = 'SIGN-OFF-HOLD' AND projects_id = ?", [project])[0]).toMatchObject({ d: expect.stringContaining("Cracked case") });
  });

  test("block adding, warn only on sending: it can't be added, and sending out needs a confirmation", async ({ asA, tenants: { a } }) => {
    const { asset, project } = await setUp(asA, a);
    expect((await assign(asA, a, project, asset)).json).toMatchObject({ result: true });
    await hold(asA, a, asset, { assign: "BLOCK", dispatch: "WARN", overridable: 1 });
    const other = await newProject(asA, a, a.users.full.id, dates());
    expect((await assign(asA, a, other, asset)).json).toMatchObject({ result: false });
    const warned = (await setStatus(asA, a, project, asset, out)).json;
    expect(warned).toMatchObject({ result: false, error: { code: "HOLD-WARNING", holds: [{ assets_id: asset, mode: "WARN" }] } });
    expect(assignmentOf(project, asset).status).not.toBe(out);
    expect((await setStatus(asA, a, project, asset, out, { holdOverride: 1 })).json).toMatchObject({ result: true });
  });

  test("moving between statuses that already leave the warehouse doesn't ask again, and an asset taken off hold can be sent", async ({ asA, tenants: { a } }) => {
    const { asset, project } = await setUp(asA, a);
    expect((await assign(asA, a, project, asset)).json).toMatchObject({ result: true });
    expect((await setStatus(asA, a, project, asset, out)).json).toMatchObject({ result: true });
    const id = await hold(asA, a, asset, { assign: "ALLOW", dispatch: "BLOCK", overridable: 0 });
    expect((await setStatus(asA, a, project, asset, back)).json).toMatchObject({ result: true });
    expect((await setStatus(asA, a, project, asset, storage)).json).toMatchObject({ result: true });
    expect((await setStatus(asA, a, project, asset, out)).json).toMatchObject({ result: false });
    expect((await asA.api("/api/assets/holds/delete.php", { instances_id: a.instanceId, assetHolds_id: id })).json).toMatchObject({ result: true });
    expect((await setStatus(asA, a, project, asset, out)).json).toMatchObject({ result: true });
  });

  test("sending out by tag is held back the same way", async ({ asA, tenants: { a } }) => {
    const { asset, project } = await setUp(asA, a, { assign: "ALLOW", dispatch: "BLOCK", overridable: 1 });
    expect((await assign(asA, a, project, asset)).json).toMatchObject({ result: true });
    const [{ tag }] = dbQuery<{ tag: string }>("SELECT assets_tag tag FROM assets WHERE assets_id = ?", [asset]);
    const byTag = (extra: Record<string, string | number> = {}) => asA.api("/api/projects/assets/setStatusByTag.php", { instances_id: a.instanceId, projects_id: project, text: tag, assetsAssignments_status: out, ...extra });
    expect((await byTag()).json).toMatchObject({ result: false, error: { code: "HOLD-BLOCKED-OVERRIDABLE" } });
    expect(assignmentOf(project, asset).status).not.toBe(out);
    expect((await byTag({ holdOverride: 1 })).json).toMatchObject({ result: true });
    expect(assignmentOf(project, asset).status).toBe(out);
  });

  test("a bulk change is refused as a whole if any asset in it can't be sent", async ({ asA, tenants: { a } }) => {
    const free = await newAsset(asA, a);
    const { asset: held, project } = await setUp(asA, a, { assign: "ALLOW", dispatch: "BLOCK", overridable: 0 });
    expect((await assign(asA, a, project, free)).json).toMatchObject({ result: true });
    expect((await assign(asA, a, project, held)).json).toMatchObject({ result: true });
    const result = await asA.api("/api/projects/assets/setStatus.php", { instances_id: a.instanceId, projects_id: project, assetsAssignments_status: out });
    expect(result.json).toMatchObject({ result: false, error: { code: "HOLD-BLOCKED" } });
    expect(assignmentOf(project, free).status).not.toBe(out);
    expect(assignmentOf(project, held).status).not.toBe(out);
  });

  test("a maintenance job's block works as it always has, and its sending out rule can be set and signed off", async ({ asA, tenants: { a } }) => {
    const { asset, project } = await setUp(asA, a);
    expect((await assign(asA, a, project, asset)).json).toMatchObject({ result: true });
    const created = await asA.api("/api/maintenance/newJob.php", {
      instances_id: a.instanceId, formData: formData({ maintenanceJobs_assets: String(asset), maintenanceJobs_title: "Fault", maintenanceJobs_priority: 5 }),
    });
    const job: number = created.json.response.maintenanceJobs_id;
    const dispatchRule = (mode: string, extra: Record<string, string | number> = {}) => asA.api("/api/maintenance/job/changeDispatchHold.php", { instances_id: a.instanceId, maintenanceJobs_id: job, maintenanceJobs_dispatchMode: mode, ...extra });
    // Blocking the job's assets keeps blocking adding them to projects, and doesn't change sending them out
    expect((await asA.api("/api/maintenance/job/changeBlock.php", { instances_id: a.instanceId, maintenanceJobs_id: job, maintenanceJobs_blockAssets: 1 })).json).toMatchObject({ result: true });
    const other = await newProject(asA, a, a.users.full.id, dates());
    expect((await assign(asA, a, other, asset)).json).toMatchObject({ result: false });
    expect((await setStatus(asA, a, project, asset, out)).json).toMatchObject({ result: true });
    expect((await setStatus(asA, a, project, asset, storage)).json).toMatchObject({ result: true });

    expect((await dispatchRule("NOPE")).json).toMatchObject({ result: false });
    expect((await dispatchRule("BLOCK", { maintenanceJobs_dispatchOverridable: 2 })).json).toMatchObject({ result: false });
    expect((await dispatchRule("BLOCK", { maintenanceJobs_dispatchOverridable: 1 })).json).toMatchObject({ result: true });
    expect((await setStatus(asA, a, project, asset, out)).json).toMatchObject({ result: false, error: { code: "HOLD-BLOCKED-OVERRIDABLE" } });
    expect((await dispatchRule("BLOCK", { maintenanceJobs_dispatchOverridable: 0 })).json).toMatchObject({ result: true });
    expect((await setStatus(asA, a, project, asset, out, { holdOverride: 1 })).json).toMatchObject({ result: false, error: { code: "HOLD-BLOCKED" } });
    // The job page shows it, and closing the job lifts it
    expect((await asA.page(`/maintenance/job.php?id=${job}`)).body).toContain('id="maintenanceJobsdispatchMode"');
    expect((await asA.api("/api/maintenance/job/changeJobStatus.php", { instances_id: a.instanceId, maintenanceJobs_id: job, maintenanceJobsStatuses_id: 2 })).json).toMatchObject({ result: true });
    expect(dbQuery("SELECT maintenanceJobs_dispatchMode m FROM maintenanceJobs WHERE maintenanceJobs_id = ?", [job])[0]).toMatchObject({ m: "ALLOW" });
    expect((await setStatus(asA, a, project, asset, out)).json).toMatchObject({ result: true });
  });

  test("holds can be edited and checked for real values, and the asset page lists them", async ({ asA, tenants: { a } }) => {
    const asset = await newAsset(asA, a);
    const id = await hold(asA, a, asset, { assign: "ALLOW", dispatch: "WARN", overridable: 1, reason: "Check the fan" });
    const page = (await asA.page(`/asset.php?id=${a.assetTypeId}&asset=${asset}`)).body;
    expect(page).toContain('id="assetHolds"');
    expect(page).toContain("Check the fan");
    const save = (fields: Record<string, string | number>) => asA.api("/api/assets/holds/save.php", { instances_id: a.instanceId, assetHolds_id: id, ...fields });
    for (const bad of [{ assetHolds_assignMode: "NEVER" }, { assetHolds_dispatchMode: "" }, { assetHolds_overridable: 5 }, {}]) {
      expect((await save(bad)).json, JSON.stringify(bad)).toMatchObject({ result: false });
    }
    expect((await save({ assetHolds_dispatchMode: "BLOCK", assetHolds_overridable: 0 })).json).toMatchObject({ result: true });
    expect(dbQuery("SELECT assetHolds_dispatchMode d, assetHolds_overridable o FROM assetHolds WHERE assetHolds_id = ?", [id])[0]).toEqual({ d: "BLOCK", o: 0 });
    expect((await asA.api("/api/assets/holds/save.php", { instances_id: a.instanceId, assets_id: 0 })).json).toMatchObject({ result: false });
    expect((await asA.api("/api/assets/holds/delete.php", { instances_id: a.instanceId, assetHolds_id: id })).json).toMatchObject({ result: true });
    expect((await asA.page(`/asset.php?id=${a.assetTypeId}&asset=${asset}`)).body).not.toContain("Check the fan");
  });

  test("a status's effect can be set, cleared and only to a known value", async ({ asA, tenants: { a } }) => {
    const effect = () => dbQuery<{ e: string | null }>("SELECT assetsAssignmentsStatus_effect e FROM assetsAssignmentsStatus WHERE assetsAssignmentsStatus_id = ?", [storage])[0].e;
    const edit = (fields: Record<string, string>) => asA.api("/api/instances/assetAssignmentStatus/edit.php", { instances_id: a.instanceId, statusId: storage, statusName: "Hold test stored", ...fields });
    expect((await edit({ statusEffect: "TELEPORTS" })).json).toMatchObject({ result: false });
    expect(effect()).toBeNull();
    expect((await edit({ statusEffect: "LEAVES_WAREHOUSE" })).json).toMatchObject({ result: true });
    expect(effect()).toBe("LEAVES_WAREHOUSE");
    expect((await edit({})).json).toMatchObject({ result: true }); // Not sent: it keeps its effect
    expect(effect()).toBe("LEAVES_WAREHOUSE");
    expect((await edit({ statusEffect: "RETURNS_TO_WAREHOUSE" })).json).toMatchObject({ result: true });
    expect(effect()).toBe("RETURNS_TO_WAREHOUSE");
    expect((await edit({ statusEffect: "" })).json).toMatchObject({ result: true });
    expect(effect()).toBeNull();
    expect((await asA.page("/instances/configuration/asset-status.php")).body).toContain("assetStatusEffect");
  });

  test("another business's assets and holds can't be put on hold or changed", async ({ asA, tenants: { a, b } }) => {
    const heldBefore = dbQuery("SELECT COUNT(*) c FROM assetHolds WHERE assets_id = ?", [b.assetId]);
    expect((await asA.api("/api/assets/holds/save.php", { instances_id: a.instanceId, assets_id: b.assetId, assetHolds_assignMode: "BLOCK" })).json).toMatchObject({ result: false });
    expect(dbQuery("SELECT COUNT(*) c FROM assetHolds WHERE assets_id = ?", [b.assetId])).toEqual(heldBefore);
    // Its holds on the assets that the business's own jobs and holds name, not B's
    const asset = await newAsset(asA, a);
    expect(await hold(asA, a, asset, { assign: "ALLOW", dispatch: "WARN", overridable: 1 })).toBeGreaterThan(0);
    expect(dbQuery("SELECT instances_id i FROM assetHolds WHERE assets_id = ?", [asset])).toEqual([{ i: a.instanceId }]);
  });

  test("on the dispatch board, a held asset asks whoever is sending it to confirm, and Cancel leaves it where it was", async ({ page, asA, tenants: { a, password } }) => {
    await cacheCdn(page.context());
    await login(page, a.users.full.email, password);
    const { asset, project } = await setUp(asA, a, { assign: "ALLOW", dispatch: "BLOCK", overridable: 1 });
    expect((await assign(asA, a, project, asset)).json).toMatchObject({ result: true });
    const [{ tag }] = dbQuery<{ tag: string }>("SELECT assets_tag tag FROM assets WHERE assets_id = ?", [asset]);
    const dispatch = async () => {
      await page.goto(`/project/?id=${project}`);
      await page.locator('a[href="#assets-board"]').click();
      await page.locator("#navItemQuickDispatch button").click();
      await page.locator(".assetStatusSelectorQuickDispatch:visible").selectOption(String(out));
      await page.locator("#assetTagQuickDispatch").fill(tag);
      await page.locator("#goQuickDispatch").click();
    };
    await dispatch();
    const dialog = page.locator(".bootbox.modal.show");
    await expect(dialog).toContainText("Sign off sending out held assets?");
    await expect(dialog).toContainText("Cracked case");
    await dialog.locator("button.bootbox-cancel").click();
    await expect(dialog).toHaveCount(0);
    expect(assignmentOf(project, asset).status).not.toBe(out);

    await dispatch();
    await expect(dialog).toContainText("Cracked case");
    await dialog.locator("button.bootbox-accept").click();
    await expect.poll(() => assignmentOf(project, asset).status).toBe(out);
  });

  test("skipping past a status that leaves the warehouse is held back too, unless it ends on a status that returns the gear to the warehouse", async ({ asA, tenants: { a } }) => {
    // In the business's order: before, leaves, still out (no effect), returns, later - after the statuses the other tests use
    dbQuery(`UPDATE assetsAssignmentsStatus SET assetsAssignmentsStatus_order = assetsAssignmentsStatus_order + 9000 WHERE assetsAssignmentsStatus_id IN (${out}, ${back}, ${storage})`);
    const ids = {
      before: await statusFor(a, "Hold test before", null, 5000), gone: await statusFor(a, "Hold test gone", "LEAVES_WAREHOUSE", 5001),
      stillOut: await statusFor(a, "Hold test still out", null, 5002), back: await statusFor(a, "Hold test back", "RETURNS_TO_WAREHOUSE", 5003),
      later: await statusFor(a, "Hold test later", null, 5004),
    };
    try {
      const { asset, project } = await setUp(asA, a, { assign: "ALLOW", dispatch: "BLOCK", overridable: 0 });
      expect((await assign(asA, a, project, asset)).json).toMatchObject({ result: true });
      const to = async (status: number) => (await setStatus(asA, a, project, asset, status)).json;
      const stays = (status: number) => expect(assignmentOf(project, asset).status).toBe(status);

      // With no status yet, going straight past "gone" is the same as going out
      expect(await to(ids.stillOut)).toMatchObject({ result: false, error: { code: "HOLD-BLOCKED" } });
      expect(assignmentOf(project, asset).status).not.toBe(ids.stillOut);
      expect(await to(ids.before)).toMatchObject({ result: true });
      expect(await to(ids.later)).toMatchObject({ result: false, error: { code: "HOLD-BLOCKED" } });
      stays(ids.before);
      // Ending on a status that returns the gear to the warehouse isn't going out, and what's after it isn't either
      expect(await to(ids.back)).toMatchObject({ result: true });
      expect(await to(ids.later)).toMatchObject({ result: true });
      // Going back and trying again is caught again
      expect(await to(ids.before)).toMatchObject({ result: true });
      expect(await to(ids.later)).toMatchObject({ result: false, error: { code: "HOLD-BLOCKED" } });
      expect(await to(ids.gone)).toMatchObject({ result: false, error: { code: "HOLD-BLOCKED" } });
      // Once it's out (by whatever route), moving along isn't checked again
      dbQuery("UPDATE assetsAssignments SET assetsAssignmentsStatus_id = ? WHERE assetsAssignments_id = ?", [ids.gone, assignmentOf(project, asset).id]);
      expect(await to(ids.stillOut)).toMatchObject({ result: true });
      expect(await to(ids.later)).toMatchObject({ result: true });
      // A status the business hasn't put in order around one that sends gear out is unaffected
      expect(await to(storage)).toMatchObject({ result: true });
      // Signing off works for skipping past too
      dbQuery("UPDATE assetHolds SET assetHolds_overridable = 1 WHERE assets_id = ?", [asset]);
      dbQuery("UPDATE assetsAssignments SET assetsAssignmentsStatus_id = ? WHERE assetsAssignments_id = ?", [ids.before, assignmentOf(project, asset).id]);
      expect((await setStatus(asA, a, project, asset, ids.later)).json).toMatchObject({ result: false, error: { code: "HOLD-BLOCKED-OVERRIDABLE" } });
      expect((await setStatus(asA, a, project, asset, ids.later, { holdOverride: 1 })).json).toMatchObject({ result: true });
    } finally {
      dbQuery(`UPDATE assetsAssignmentsStatus SET assetsAssignmentsStatus_deleted = 1 WHERE assetsAssignmentsStatus_id IN (${Object.values(ids).join(",")})`);
    }
  });
});
