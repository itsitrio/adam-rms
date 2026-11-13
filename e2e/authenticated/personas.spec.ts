import { test, expect, dbQuery, newSession, succeeded, type Session, type Tenant } from "../tenants";
import { BASE_URL } from "../env";
import { newProject } from "../projects";
import { login } from "../fixtures";
import { cacheCdn } from "../cdn";

/**
 * Personas: another name (and optionally email) a user goes by in a business. A project can show one of
 * its manager's personas in place of the manager's own name and email - on quotes and invoices, to crew,
 * on vacancies and in calendars - so one account can be used under several names.
 */

const dates = { start: "2031-03-01 09:00:00", end: "2031-03-02 18:00:00" };

async function newPersona(session: Session, t: Tenant, name: string, email = "") {
  const saved = await session.api("/api/account/personas/save.php", { instances_id: t.instanceId, usersPersonas_name: name, usersPersonas_email: email });
  expect(saved.json, `creating persona ${name}`).toMatchObject({ result: true });
  return saved.json.response.usersPersonas_id as number;
}

function choosePersona(session: Session, t: Tenant, projectId: number, personaId: number | "") {
  return session.api("/api/projects/changeProjectManagerPersona.php", { instances_id: t.instanceId, projects_id: projectId, usersPersonas_id: personaId });
}

function projectPersona(projectId: number) {
  return dbQuery<{ projects_manager_persona: number | null }>("SELECT projects_manager_persona FROM projects WHERE projects_id = ?", [projectId])[0].projects_manager_persona;
}

/** The manager's name and email as printed on the project's quote (projectInvoice.php builds the PDF in the browser) */
async function quoteManager(session: Session, projectId: number) {
  const page = await session.page(`/project/projectInvoice.php?id=${projectId}&type=quote&pmdetails=1`);
  expect(page.status, "opening the quote").toBe(200);
  const match = page.body.match(/\{ text: "([^"]*)", style: \{ bold: true, alignment: "right" \} \},\s*\{text: "([^"]*)", style: \{ alignment: "right" \} \}/);
  expect(match, "the quote shows the project manager").not.toBeNull();
  return { name: JSON.parse(`"${match![1]}"`), email: JSON.parse(`"${match![2]}"`) };
}

test("a project shows its manager's persona on its quote, and their own name without one", async ({ tenants, asA }) => {
  const a = tenants.a;
  const projectId = await newProject(asA, a, a.users.full.id, dates);
  const own = await quoteManager(asA, projectId);
  expect(own.email).toBe(a.users.full.email);

  const personaId = await newPersona(asA, a, "DJ Persona Test", "dj@example.com");
  expect(succeeded(await choosePersona(asA, a, projectId, personaId))).toBe(true);
  expect(await quoteManager(asA, projectId)).toEqual({ name: "DJ Persona Test", email: "dj@example.com" });

  const data = await asA.api("/api/projects/data.php", { instances_id: a.instanceId, id: projectId });
  expect(data.json.response.project).toMatchObject({ projects_manager_name: "DJ Persona Test", projects_manager_email: "dj@example.com" });

  // Renaming the persona changes what the project shows; leaving the email blank shows the user's own
  const renamed = await asA.api("/api/account/personas/save.php", { instances_id: a.instanceId, usersPersonas_id: personaId, usersPersonas_name: "DJ Renamed", usersPersonas_email: "" });
  expect(succeeded(renamed)).toBe(true);
  expect(await quoteManager(asA, projectId)).toEqual({ name: "DJ Renamed", email: a.users.full.email });

  // Choosing no persona goes back to their own name
  expect(succeeded(await choosePersona(asA, a, projectId, ""))).toBe(true);
  expect(await quoteManager(asA, projectId)).toEqual(own);
});

test("the project page offers the manager's personas, and the account page lists them", async ({ tenants, asA }) => {
  const a = tenants.a;
  const projectId = await newProject(asA, a, a.users.full.id, dates);
  await newPersona(asA, a, "Page Persona Test");

  const project = await asA.page(`/project/?id=${projectId}&instances_id=${a.instanceId}`);
  expect(project.body).toContain('id="editProjectManagerPersona"');
  expect(project.body).toContain("Page Persona Test");

  const account = await asA.page(`/user.php?instances_id=${a.instanceId}`);
  expect(account.body).toContain('id="personas"');
  expect(account.body).toContain("Page Persona Test");
});

test("changing the project manager drops the old manager's persona", async ({ tenants, asA }) => {
  const a = tenants.a;
  const projectId = await newProject(asA, a, a.users.full.id, dates);
  const personaId = await newPersona(asA, a, "Dropped Persona Test");
  expect(succeeded(await choosePersona(asA, a, projectId, personaId))).toBe(true);

  const changed = await asA.api("/api/projects/changeProjectManager.php", { instances_id: a.instanceId, projects_id: projectId, users_userid: a.users.limited.id });
  expect(succeeded(changed)).toBe(true);
  expect(projectPersona(projectId)).toBeNull();
});

test("a deleted persona can't be chosen, but stays on projects already showing it", async ({ tenants, asA }) => {
  const a = tenants.a;
  const projectId = await newProject(asA, a, a.users.full.id, dates);
  const otherProjectId = await newProject(asA, a, a.users.full.id, dates);
  const personaId = await newPersona(asA, a, "Deleted Persona Test");
  expect(succeeded(await choosePersona(asA, a, projectId, personaId))).toBe(true);

  expect(succeeded(await asA.api("/api/account/personas/delete.php", { instances_id: a.instanceId, usersPersonas_id: personaId }))).toBe(true);
  expect((await quoteManager(asA, projectId)).name).toBe("Deleted Persona Test");
  expect(succeeded(await choosePersona(asA, a, otherProjectId, personaId))).toBe(false);
  expect(projectPersona(otherProjectId)).toBeNull();
});

test("a project can only show a persona of its own manager", async ({ tenants, asA, asLimitedA }) => {
  const a = tenants.a;
  const projectId = await newProject(asA, a, a.users.full.id, dates);
  const limitedPersona = await newPersona(asLimitedA, a, "Someone Else Persona Test");
  expect(succeeded(await choosePersona(asA, a, projectId, limitedPersona))).toBe(false);
  expect(projectPersona(projectId)).toBeNull();
});

test("a persona from another business can't be shown on a project", async ({ tenants, asA, playwright }) => {
  const { a, b, sharedUser } = tenants;
  const request = await playwright.request.newContext({ baseURL: BASE_URL });
  const asShared = await newSession(request, sharedUser.email, tenants.password);
  const personaInB = await newPersona(asShared, b, "Business B Persona Test");
  expect(dbQuery("SELECT instances_id FROM usersPersonas WHERE usersPersonas_id = ?", [personaInB])[0]).toEqual({ instances_id: b.instanceId });

  // A project in A managed by the shared user can't show the persona they use in B
  const projectId = await newProject(asA, a, sharedUser.id, dates);
  expect(succeeded(await choosePersona(asA, a, projectId, personaInB))).toBe(false);
  expect(succeeded(await choosePersona(asShared, a, projectId, personaInB))).toBe(false);
  expect(projectPersona(projectId)).toBeNull();

  // Nor can A's user choose a persona for B's project
  expect(succeeded(await choosePersona(asA, a, b.projectId, personaInB))).toBe(false);
  expect(dbQuery("SELECT projects_manager_persona FROM projects WHERE projects_id = ?", [b.projectId])[0]).toEqual({ projects_manager_persona: null });
  await request.dispose();
});

test("only a persona's own user can change or delete it", async ({ tenants, asA, asLimitedA }) => {
  const a = tenants.a;
  const personaId = await newPersona(asA, a, "Owned Persona Test");
  expect(succeeded(await asLimitedA.api("/api/account/personas/save.php", { instances_id: a.instanceId, usersPersonas_id: personaId, usersPersonas_name: "Hijacked" }))).toBe(false);
  expect(succeeded(await asLimitedA.api("/api/account/personas/delete.php", { instances_id: a.instanceId, usersPersonas_id: personaId }))).toBe(false);
  expect(dbQuery("SELECT usersPersonas_name, usersPersonas_deleted FROM usersPersonas WHERE usersPersonas_id = ?", [personaId])[0]).toEqual({ usersPersonas_name: "Owned Persona Test", usersPersonas_deleted: 0 });
});

test("personas need a name and a valid email if one is given", async ({ tenants, asA }) => {
  const a = tenants.a;
  expect(succeeded(await asA.api("/api/account/personas/save.php", { instances_id: a.instanceId, usersPersonas_name: "  " }))).toBe(false);
  expect(succeeded(await asA.api("/api/account/personas/save.php", { instances_id: a.instanceId, usersPersonas_name: "Bad Email", usersPersonas_email: "not-an-email" }))).toBe(false);
});

test("a project's manager can choose their own persona without permission to change the manager", async ({ tenants, asA, asLimitedA }) => {
  const a = tenants.a;
  const managedByLimited = await newProject(asA, a, a.users.limited.id, dates);
  const managedByFull = await newProject(asA, a, a.users.full.id, dates);
  const personaId = await newPersona(asLimitedA, a, "Limited Own Persona Test");

  expect(succeeded(await choosePersona(asLimitedA, a, managedByLimited, personaId))).toBe(true);
  expect(projectPersona(managedByLimited)).toBe(personaId);

  const fullPersona = await newPersona(asA, a, "Full Persona Test");
  expect(succeeded(await choosePersona(asLimitedA, a, managedByFull, fullPersona))).toBe(false);
  expect(projectPersona(managedByFull)).toBeNull();
});

test("crew see the persona on the vacancy page and in the business's calendar feed", async ({ tenants, asA }) => {
  const a = tenants.a;
  // The seeded project has an open vacancy; give its manager a persona and have the project show it
  const [before] = dbQuery<{ projects_manager: number; projects_manager_persona: number | null; instances_calendarHash: string | null }>(
    "SELECT projects_manager, projects_manager_persona, instances_calendarHash FROM projects JOIN instances USING (instances_id) WHERE projects_id = ?", [a.projectId]);
  const personaId = await newPersona(asA, a, "Crew Persona Test", "crew-persona@example.com");
  dbQuery("UPDATE usersPersonas SET users_userid = ? WHERE usersPersonas_id = ?", [before.projects_manager, personaId]);
  dbQuery("UPDATE projects SET projects_manager_persona = ? WHERE projects_id = ?", [personaId, a.projectId]);
  dbQuery("UPDATE instances SET instances_calendarHash = ? WHERE instances_id = ?", ["personaspeccalendarkey", a.instanceId]);
  try {
    const vacancy = await asA.page(`/project/crew/vacancy.php?id=${a.vacantRoleId}&instances_id=${a.instanceId}`);
    expect(vacancy.status).toBe(200);
    expect(vacancy.body).toContain("Crew Persona Test (crew-persona@example.com)");

    const feed = await asA.api("/api/instances/calendar-export.php", { id: a.instanceId, key: "personaspeccalendarkey" });
    expect(feed.body.replace(/\r?\n[ \t]/g, "")).toContain("Project Manager: Crew Persona Test");
  } finally {
    dbQuery("UPDATE projects SET projects_manager_persona = ? WHERE projects_id = ?", [before.projects_manager_persona, a.projectId]);
    dbQuery("UPDATE instances SET instances_calendarHash = ? WHERE instances_id = ?", [before.instances_calendarHash, a.instanceId]);
  }
});

test("a manager adds a persona on their account page and picks it on their project", async ({ tenants, asA, page }) => {
  const a = tenants.a;
  const projectId = await newProject(asA, a, a.users.full.id, dates);
  await cacheCdn(page.context());
  await login(page, a.users.full.email, tenants.password);

  await page.goto("/user.php#personas");
  const newRow = page.locator('#personasTable tr[data-personaid=""]');
  await newRow.locator(".personaName").fill("Browser Persona Test");
  await newRow.locator(".personaEmail").fill("browser-persona@example.com");
  await newRow.getByRole("button", { name: "Add" }).click();
  await expect(page.locator('#personasTable input.personaName[value="Browser Persona Test"]')).toBeVisible();
  const [{ usersPersonas_id: personaId }] = dbQuery<{ usersPersonas_id: number }>(
    "SELECT usersPersonas_id FROM usersPersonas WHERE usersPersonas_name = ? AND users_userid = ? AND instances_id = ? AND usersPersonas_deleted = 0 ORDER BY usersPersonas_id DESC LIMIT 1",
    ["Browser Persona Test", a.users.full.id, a.instanceId]);

  await page.goto(`/project/?id=${projectId}`);
  await Promise.all([page.waitForEvent("load"), page.locator("#editProjectManagerPersona").selectOption(String(personaId))]);
  await expect(page.locator("#editProjectManagerPersona")).toHaveValue(String(personaId));
  expect(projectPersona(projectId)).toBe(personaId);
});
