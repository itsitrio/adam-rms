import { cacheCdn } from "../cdn";
import { login } from "../fixtures";
import { assign, newProject } from "../projects";
import { dbQuery, expect, formData, type Session, type Tenant, test } from "../tenants";

/**
 * An asset can have a published line item name - what clients are shown instead of its make, model and ID, for
 * when a business promises something that does the job without saying exactly which piece of kit it is. A project
 * shows every asset by its published name, or only the lines set to, and a document can merge lines that share one.
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

/** A new asset type of its own with one asset, published as `publishedName` if given */
async function assetOfType(session: Session, t: Tenant, name: string, publishedName?: string) {
  const type = await session.api("/api/assets/newAssetType.php", { instances_id: t.instanceId, formData: formData({
    manufacturers_id: t.manufacturerId, assetCategories_id: t.categoryId, assetTypes_name: name, assetTypes_dayRate: "10.00",
  }) });
  expect(type.json, "creating the asset type").toMatchObject({ result: true });
  const asset = await session.api("/api/assets/newAssetFromType.php", { instances_id: t.instanceId, formData: formData({ assetTypes_id: type.json.response.assetTypes_id }) });
  const assetId = asset.json.response.assets_id as number;
  if (publishedName !== undefined) await ok(session.api("/api/assets/editAsset.php", { instances_id: t.instanceId, assets_id: assetId, assets_publishedName: publishedName }), "naming it");
  return assetId;
}

test("an asset's published name is saved trimmed, and cleared when emptied", async ({ asA, tenants: { a } }) => {
  const asset = await assetOfType(asA, a, `Named mixer ${Date.now()}`, "  Digital Mixing Console  ");
  expect(dbQuery("SELECT assets_publishedName n FROM assets WHERE assets_id = ?", [asset])).toEqual([{ n: "Digital Mixing Console" }]);
  const page = await asA.page(`/asset.php?id=${dbQuery<{ t: number }>("SELECT assetTypes_id t FROM assets WHERE assets_id = ?", [asset])[0].t}&asset=${asset}`);
  expect(page.body).toContain('value="Digital Mixing Console"');
  await ok(asA.api("/api/assets/editAsset.php", { instances_id: a.instanceId, assets_id: asset, assets_publishedName: "" }), "clearing it");
  expect(dbQuery("SELECT assets_publishedName n FROM assets WHERE assets_id = ?", [asset])).toEqual([{ n: null }]);
});

test("documents show the lines chosen, or every asset, by their published name, and can merge them", async ({ asA, tenants: { a } }) => {
  const stamp = Date.now();
  const names = { mic: `Secret mic ${stamp}`, otherMic: `Other secret mic ${stamp}`, cable: `Plain cable ${stamp}`, unnamed: `Unnamed desk ${stamp}` };
  const published = `Wireless Microphone System ${stamp}`;
  const mic = await assetOfType(asA, a, names.mic, published);
  const otherMic = await assetOfType(asA, a, names.otherMic, published);
  const cable = await assetOfType(asA, a, names.cable);
  const unnamed = await assetOfType(asA, a, names.unnamed);
  // One day: each costs £10.00
  const project = await newProject(asA, a, a.users.full.id, { start: "2036-11-20 09:00:00", end: "2036-11-20 18:00:00" });
  for (const asset of [mic, otherMic, cable, unnamed]) await ok(assign(asA, a, project, asset), "assigning");
  const open = async (type: string, options = "&publishedNames=on") => {
    const page = await asA.page(`/project/projectInvoice.php?id=${project}&type=${type}&finance=1&assets=1&prices=1&showAll=on${options}`);
    expect(page.status).toBe(200);
    return page.body;
  };
  const shown = (body: string) => Object.fromEntries(Object.entries(names).map(([key, name]) => [key, body.includes(jsEscaped(name))]));

  await test.step("nothing is masked until the project or a line asks for it", async () => {
    const body = await open("quote");
    expect(shown(body)).toEqual({ mic: true, otherMic: true, cable: true, unnamed: true });
    expect(body).not.toContain(jsEscaped(published));
  });

  await test.step("a line set to use its published name is shown by it", async () => {
    await ok(asA.api("/api/projects/assets/setPublishedName.php", { instances_id: a.instanceId, assetsAssignments: [assignmentOf(project, mic)], assetsAssignments_publishedName: 1 }), "masking the mic");
    for (const type of ["quote", "invoice", "deliveryNote"]) {
      const body = await open(type);
      expect(shown(body), `the ${type}`).toEqual({ mic: false, otherMic: true, cable: true, unnamed: true });
      expect(body).toContain(jsEscaped(published));
    }
    // A document can be made with what everything really is
    expect(shown(await open("quote", ""))).toEqual({ mic: true, otherMic: true, cable: true, unnamed: true });
  });

  await test.step("a line set to use a published name it doesn't have is shown as it is", async () => {
    await ok(asA.api("/api/projects/assets/setPublishedName.php", { instances_id: a.instanceId, assetsAssignments: [assignmentOf(project, unnamed)], assetsAssignments_publishedName: 1 }), "masking the desk");
    expect(shown(await open("quote")).unnamed).toBe(true);
  });

  await test.step("the whole project can use published names, and lines sharing one can be merged", async () => {
    await ok(asA.api("/api/projects/changePublishedNames.php", { instances_id: a.instanceId, projects_id: project, projects_publishedNames: 1 }), "masking everything");
    let body = await open("quote");
    expect(shown(body)).toEqual({ mic: false, otherMic: false, cable: true, unnamed: true });
    // Kept apart, each is its own line
    expect(body.split(`"${jsEscaped(published)}`).length - 1).toBe(2);
    body = await open("quote", "&publishedNames=on&mergePublishedNames=on");
    expect(body).toContain(`"2x ${jsEscaped(published)}"`);
    expect(body, "the merged line costs both").toContain(`'${jsEscaped("£20.00")}'`);
  });

  await test.step("the asset list shows which lines are published under another name", async () => {
    const page = await asA.page(`/project/?id=${project}`);
    expect(page.body).toContain("assignmentPublishedNameBadge");
    expect(page.body).toContain(published);
  });

  await test.step("turning it off for the project leaves only the lines chosen", async () => {
    await ok(asA.api("/api/projects/changePublishedNames.php", { instances_id: a.instanceId, projects_id: project, projects_publishedNames: 0 }), "unmasking everything");
    expect(shown(await open("quote"))).toEqual({ mic: false, otherMic: true, cable: true, unnamed: true });
    await ok(asA.api("/api/projects/assets/setPublishedName.php", { instances_id: a.instanceId, assetsAssignments: [assignmentOf(project, mic)], assetsAssignments_publishedName: 0 }), "unmasking the mic");
    expect(shown(await open("quote"))).toEqual({ mic: true, otherMic: true, cable: true, unnamed: true });
  });
});

test("choosing published names from the project's asset list", async ({ page, asA, tenants: { a, password } }) => {
  await cacheCdn(page.context());
  await login(page, a.users.full.email, password);
  const project = await newProject(asA, a, a.users.full.id, { start: "2036-11-25 09:00:00", end: "2036-11-25 18:00:00" });
  const asset = await assetOfType(asA, a, `Listed mic ${Date.now()}`, "Handheld Microphone");
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
  await page.locator("#setAssetAssignmentPublishedName").click();
  await page.locator("#assignmentPublishedName").selectOption("1");
  await Promise.all([page.waitForEvent("load"), page.getByRole("button", { name: "Save" }).click()]);
  expect(dbQuery("SELECT assetsAssignments_publishedName p FROM assetsAssignments WHERE assetsAssignments_id = ?", [line])).toEqual([{ p: 1 }]);
  await openAssetList();
  await expect(page.locator(`#assetRow${line} .assignmentPublishedNameBadge`)).toContainText("Handheld Microphone");

  // With nothing selected, only the whole project's setting is offered
  await page.locator("#setAssetAssignmentPublishedName").click();
  await expect(page.locator("#assignmentPublishedName")).toHaveCount(0);
  await page.locator("#projectPublishedNames").selectOption("1");
  await Promise.all([page.waitForEvent("load"), page.getByRole("button", { name: "Save" }).click()]);
  expect(dbQuery("SELECT projects_publishedNames p FROM projects WHERE projects_id = ?", [project])).toEqual([{ p: 1 }]);
});
