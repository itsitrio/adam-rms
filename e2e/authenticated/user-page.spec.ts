import { expect, test } from "../tenants";

/** A user's profile page (user.php), as seen by another member of their business */

test("Projects Managed lists the sub-projects a user manages, not just whole projects", async ({ asA, tenants: { a } }) => {
  const create = async (name: string, managerId: number, parent?: number) => {
    const created = await asA.api("/api/projects/new.php", {
      instances_id: a.instanceId, projects_name: name, projects_manager: managerId, projectsType_id: a.projectTypeId,
      ...(parent ? { projects_parent_project_id: parent } : {}),
    });
    expect(created.json, `creating ${name}`).toMatchObject({ result: true });
    return created.json.response.projects_id as number;
  };
  const marker = `e2e managed ${Date.now()}`;
  const parent = await create(`${marker} parent`, a.users.full.id);
  // Someone other than the parent's manager runs this part of the job
  await create(`${marker} sub-project`, a.users.limited.id, parent);

  const page = await asA.page(`/user.php?id=${a.users.limited.id}`);
  expect(page.status).toBe(200);
  // The tab, not the sidebar's project list, which shows sub-projects anyway
  const tab = page.body.slice(page.body.indexOf('id="projects"'));
  expect(tab.slice(0, tab.indexOf("</table>"))).toContain(`${marker} sub-project`);
});
