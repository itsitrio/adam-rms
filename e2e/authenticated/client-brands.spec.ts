import { assign, newAsset, newProject } from "../projects";
import { dbQuery, expect, formData, succeeded, test, type Session } from "../tenants";

/**
 * Event brands: a client's recurring events (one convention every year, or a company that runs several), which its
 * projects can be tagged with. client.php shows, per brand, how many projects it had, when, how much kit went out on
 * them and what they came to, plus a row for the client's projects with no brand.
 */

const gbp = (pence: number) => new Intl.NumberFormat("en-GB", { style: "currency", currency: "GBP" }).format(pence / 100);

type Row = { projects: string; first: string; last: string; assets: string; total: string; projectNames: string[] };
/** The rows of client.php's event brands table, by brand name ("" for the no brand row) */
async function brandRows(session: Session, clientId: number) {
  const page = await session.page(`/client.php?id=${clientId}`);
  expect(page.status).toBe(200);
  const rows: Record<string, Row> = {};
  const parts = page.body.split('<tr class="brandRow"').slice(1);
  for (const part of parts) {
    const cell = (column: string) => part.match(new RegExp(`data-column="${column}">([^<]*)<`))![1].trim();
    const dates = [...part.matchAll(/<td>(\d\d \w{3} \d{4})?<\/td>/g)].map((m) => m[1] ?? "");
    const projectNames = [...part.matchAll(/href="[^"]*\/project\/\?id=\d+">([^<]+)<\/a>/g)].map((m) => m[1]);
    const brand = part.match(/data-brand="([^"]*)"/)![1];
    rows[brand] = { projects: cell("projects"), first: dates[0], last: dates[1], assets: cell("assets"), total: cell("total"), projectNames };
  }
  const total = (key: string) => page.body.match(new RegExp(`data-total="${key}">([^<]*)<`))![1].trim();
  return { rows, totals: { projects: total("projects"), assets: total("assets"), total: total("total") } };
}

test("a client's event brands show how often each one hires kit, and what it comes to", async ({ asA, tenants: { a } }) => {
  const ok = async (request: ReturnType<Session["api"]>, what: string) => {
    const response = await request;
    expect(response.json, what).toMatchObject({ result: true });
    return response.json;
  };
  const api = (endpoint: string, params: Parameters<Session["api"]>[1]) => asA.api(endpoint, { instances_id: a.instanceId, ...params });

  const client = (await ok(api("/api/clients/new.php", { clients_name: `Brands client ${Date.now()}` }), "creating the client")).response.clients_id as number;
  const brand = async (name: string) =>
    (await ok(api("/api/clients/brands/new.php", { clients_id: client, clientsBrands_name: name, clientsBrands_notes: `${name} notes` }), `creating ${name}`)).response.clientsBrands_id as number;
  const blfc = await brand("BLFC");
  const denfur = await brand("DenFur");

  const listed = await ok(api("/api/clients/brands/list.php", { clients_id: client }), "listing the brands");
  expect(listed.response.brands.map((b: { clientsBrands_name: string }) => b.clientsBrands_name)).toEqual(["BLFC", "DenFur"]);

  // Three projects: BLFC two years running, and one DenFur in between, each with its own kit
  const project = async (name: string, start: string, end: string, brandId: number, assets: number) => {
    const id = await newProject(asA, a, a.users.full.id, { start, end });
    await ok(api("/api/projects/changeName.php", { projects_id: id, projects_name: name }), "naming the project");
    await ok(api("/api/projects/changeClient.php", { projects_id: id, clients_id: client }), "setting the client");
    await ok(api("/api/projects/changeBrand.php", { projects_id: id, clientsBrands_id: brandId }), "setting the brand");
    for (let i = 0; i < assets; i++) expect(succeeded(await assign(asA, a, id, await newAsset(asA, a))), "booking an asset").toBe(true);
    return id;
  };
  const blfc2030 = await project("BLFC 2030", "2030-05-01 09:00:00", "2030-05-05 18:00:00", blfc, 2);
  const denfur2030 = await project("DenFur 2030", "2030-08-10 09:00:00", "2030-08-12 18:00:00", denfur, 1);
  const blfc2031 = await project("BLFC 2031", "2031-05-07 09:00:00", "2031-05-11 18:00:00", blfc, 1);
  // DenFur also bought a consumable
  await ok(api("/api/projects/newPayment.php", { formData: formData({
    projects_id: denfur2030, payments_type: 2, payments_amount: "12.50", payments_quantity: 2, payments_date: "2030-08-10",
    payments_reference: "e2e gaffer tape", payments_method: "", payments_supplier: "", payments_comment: "",
  }) }), "adding a sale");

  // What projects/data.php works out each project comes to (opening it also brings the running totals client.php reads up to date)
  const finances: Record<number, { total: number; assets: number }> = {};
  for (const id of [blfc2030, denfur2030, blfc2031]) {
    const data = (await ok(api("/api/projects/data.php", { id }), "reading the project")).response;
    finances[id] = { total: Number(data.FINANCIALS.payments.subTotal.amount), assets: data.FINANCIALS.assetsAssignedQuantity };
  }
  expect(finances[denfur2030].total, "the sale counts towards DenFur's total").toBeGreaterThan(2500);

  await test.step("each brand's projects, dates, assets and total", async () => {
    const { rows, totals } = await brandRows(asA, client);
    expect(rows["BLFC"]).toEqual({
      projects: "2", first: "01 May 2030", last: "07 May 2031", assets: "3",
      total: gbp(finances[blfc2030].total + finances[blfc2031].total), projectNames: ["BLFC 2030", "BLFC 2031"],
    });
    expect(rows["DenFur"]).toEqual({
      projects: "1", first: "10 Aug 2030", last: "10 Aug 2030", assets: "1", total: gbp(finances[denfur2030].total), projectNames: ["DenFur 2030"],
    });
    expect(rows[""], "no row for projects without a brand, when there are none").toBeUndefined();
    expect(totals).toEqual({ projects: "3", assets: "4", total: gbp(finances[blfc2030].total + finances[denfur2030].total + finances[blfc2031].total) });
  });

  await test.step("a project can only be tagged with its own client's brands", async () => {
    // The seeded brand belongs to another of A's clients
    const refused = await api("/api/projects/changeBrand.php", { projects_id: blfc2030, clientsBrands_id: a.clientBrandId });
    expect(succeeded(refused)).toBe(false);
    expect(dbQuery("SELECT clientsBrands_id FROM projects WHERE projects_id = ?", [blfc2030])[0].clientsBrands_id).toBe(blfc);
    // A brand can't be edited or deleted through another client
    expect(succeeded(await api("/api/clients/brands/edit.php", { clients_id: a.clientId, clientsBrands_id: blfc, clientsBrands_name: "Renamed by e2e" }))).toBe(false);
    expect(succeeded(await api("/api/clients/brands/delete.php", { clients_id: a.clientId, clientsBrands_id: blfc }))).toBe(false);
    expect(dbQuery("SELECT clientsBrands_name, clientsBrands_deleted FROM clientsBrands WHERE clientsBrands_id = ?", [blfc])[0]).toEqual({ clientsBrands_name: "BLFC", clientsBrands_deleted: 0 });
  });

  await test.step("the project page offers only the client's brands", async () => {
    const page = await asA.page(`/project/?id=${blfc2030}`);
    expect(page.body).toMatch(new RegExp(`<option value="${blfc}" selected>BLFC</option>`));
    expect(page.body).toContain(`<option value="${denfur}" >DenFur</option>`);
    expect(page.body).not.toContain(`${a.marker} brand</option>`);
  });

  await test.step("renaming a brand", async () => {
    await ok(api("/api/clients/brands/edit.php", { clients_id: client, clientsBrands_id: denfur, clientsBrands_name: "Denver Fur", clientsBrands_notes: "" }), "renaming DenFur");
    const { rows } = await brandRows(asA, client);
    expect(rows["Denver Fur"].projects).toBe("1");
    expect(rows["DenFur"]).toBeUndefined();
  });

  await test.step("moving a project to another client removes its brand", async () => {
    await ok(api("/api/projects/changeClient.php", { projects_id: denfur2030, clients_id: a.clientId }), "changing the client");
    expect(dbQuery("SELECT clientsBrands_id FROM projects WHERE projects_id = ?", [denfur2030])[0].clientsBrands_id).toBeNull();
    // Setting the same client again keeps the brand
    await ok(api("/api/projects/changeClient.php", { projects_id: blfc2030, clients_id: client }), "setting the same client");
    expect(dbQuery("SELECT clientsBrands_id FROM projects WHERE projects_id = ?", [blfc2030])[0].clientsBrands_id).toBe(blfc);

    const { rows, totals } = await brandRows(asA, client);
    expect(rows["Denver Fur"]).toMatchObject({ projects: "0", assets: "0", total: gbp(0), projectNames: [] });
    expect(totals.projects).toBe("2");
  });

  await test.step("a deleted brand's projects are listed under no brand", async () => {
    await ok(api("/api/clients/brands/delete.php", { clients_id: client, clientsBrands_id: blfc }), "deleting BLFC");
    const { rows } = await brandRows(asA, client);
    expect(rows["BLFC"]).toBeUndefined();
    expect(rows[""]).toMatchObject({ projects: "2", assets: "3", projectNames: ["BLFC 2030", "BLFC 2031"] });
    expect(succeeded(await api("/api/projects/changeBrand.php", { projects_id: blfc2031, clientsBrands_id: blfc })), "a deleted brand can't be set").toBe(false);
    // Removing the brand
    await ok(api("/api/projects/changeBrand.php", { projects_id: blfc2031, clientsBrands_id: "" }), "removing the brand");
    expect(dbQuery("SELECT clientsBrands_id FROM projects WHERE projects_id = ?", [blfc2031])[0].clientsBrands_id).toBeNull();
  });
});
