import { expect, test } from "../tenants";

/**
 * The search boxes behind maintenance jobs and the business's user list, and the project list the mobile app
 * uses. The searches match what they should (and, in sql-injection.spec.ts, nothing else).
 */

test("maintenance/searchAsset.php finds assets by tag or type name", async ({ asA, tenants: { a } }) => {
  const byTag = await asA.api("/api/maintenance/searchAsset.php", { instances_id: a.instanceId, term: a.assetTag });
  expect(byTag.json).toMatchObject({ result: true, response: [{ assets_id: a.assetId, assets_tag: a.assetTag }] });

  const byType = await asA.api("/api/maintenance/searchAsset.php", { instances_id: a.instanceId, term: `${a.marker} asset type` });
  expect(byType.json.response.map((row: { assets_id: number }) => row.assets_id)).toEqual(expect.arrayContaining([a.assetId, a.spareAssetId]));

  const nothing = await asA.api("/api/maintenance/searchAsset.php", { instances_id: a.instanceId, term: "no asset is called this" });
  expect(nothing.json).toMatchObject({ result: false });
});

test("maintenance/searchUser.php finds the business's users by first, last or full name", async ({ asA, tenants: { a } }) => {
  for (const term of ["Limited", `${a.marker} user`, `Limited ${a.marker}`]) {
    const response = await asA.api("/api/maintenance/searchUser.php", { instances_id: a.instanceId, term });
    expect(response.json.response.map((row: { users_userid: number }) => row.users_userid), term).toContain(a.users.limited.id);
  }
});

test("instances/users.php searches the business's users by name or email", async ({ asA, tenants: { a } }) => {
  const ids = async (q: string) => {
    const response = await asA.api("/api/instances/users.php", { instances_id: a.instanceId, q }, "GET");
    return response.json.response.users.map((user: { users_userid: number }) => user.users_userid);
  };
  expect(await ids(a.users.limited.email)).toEqual([a.users.limited.id]);
  expect(await ids("Limited")).toContain(a.users.limited.id);
  expect(await ids("no user is called this")).toEqual([]);
});

test("projects/list.php lists projects with their sub-projects", async ({ asA, tenants: { a } }) => {
  const response = await asA.api("/api/projects/list.php", { instances_id: a.instanceId, subprojects: "true" });
  expect(response.json?.result).toBe(true);
  const project = response.json.response.find((p: { projects_id: number }) => p.projects_id === a.projectId);
  expect(project.subprojects).toContainEqual(expect.objectContaining({ projects_id: a.subProjectId }));
  // Sub-projects are listed under their parent, not on their own
  expect(response.json.response.map((p: { projects_id: number }) => p.projects_id)).not.toContain(a.subProjectId);
});

test("assets/searchAssets.php finds the business's assets by type, manufacturer, notes or the end of their tag", async ({ asA, tenants: { a } }) => {
  const ids = async (term: string) => {
    const response = await asA.api("/api/assets/searchAssets.php", { instances_id: a.instanceId, term });
    return response.json.result ? response.json.response.map((row: { assets_id: number }) => row.assets_id) : [];
  };
  expect(await ids(`${a.marker} asset type`)).toEqual(expect.arrayContaining([a.assetId, a.spareAssetId]));
  expect(await ids(`${a.marker} manufacturer`)).toEqual(expect.arrayContaining([a.assetId, a.spareAssetId]));
  expect(await ids(`${a.marker} asset notes`)).toContain(a.assetId);
  expect(await ids(a.assetTag.slice(-3))).toContain(a.assetId); // a tag only matches at its end
  expect(await ids("no asset's called this")).toEqual([]);
});

test("assets/searchType.php finds asset types by name or description", async ({ asA, tenants: { a } }) => {
  const ids = async (term: string) => {
    const response = await asA.api("/api/assets/searchType.php", { instances_id: a.instanceId, term });
    return response.json.result ? response.json.response.map((row: { assetTypes_id: number }) => row.assetTypes_id) : [];
  };
  expect(await ids(`${a.marker} asset type`)).toContain(a.assetTypeId);
  expect(await ids(`${a.marker} asset type description`)).toContain(a.assetTypeId);
  expect(await ids("no type's called this")).toEqual([]);
});
