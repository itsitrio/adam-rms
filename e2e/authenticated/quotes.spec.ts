import { execFileSync } from "child_process";
import path from "path";
import type { Page } from "@playwright/test";
import { test as base, expect } from "@playwright/test";
import { login } from "../fixtures";
import { APP_ENV, PHP_BINARY, TEST_USER } from "../env";

type QuoteFixture = {
  email: string;
  instance: number;
  projects: { parent: number; stageA: number; stageB: number };
  assignments: { micsOnStageA: number; screenOnStageA: number; micsOnStageB: number };
};

function fixtures(...args: string[]) {
  return execFileSync(PHP_BINARY, ["e2e/setup/quoteFixtures.php", ...args], {
    cwd: path.resolve(__dirname, "../.."),
    env: { ...process.env, ...APP_ENV },
  }).toString();
}

/**
 * Each test gets its own user and business, with a "Festival" project that has "Main Stage" and
 * "Second Stage" sub-projects, and `page` is logged in as that user.
 */
const test = base.extend<{ festival: QuoteFixture }>({
  festival: async ({}, use) => {
    const festival: QuoteFixture = JSON.parse(fixtures("create"));
    await use(festival);
    fixtures("delete", String(festival.instance));
  },
  page: async ({ page, festival }, use) => {
    await login(page, festival.email, TEST_USER.password);
    await use(page);
  },
});

function cachedEquipmentSubTotal(projectId: number): number {
  return JSON.parse(fixtures("cache", String(projectId)));
}

async function api(page: Page, endpoint: string, form: Record<string, string | number>) {
  const response = await page.request.post(`/api/projects/quote/${endpoint}`, { form });
  return response.json();
}

/**
 * Loads the PDF page and returns the pdfmake document it builds, as JSON - captured as it's handed
 * to pdfmake, which rewrites it while making the PDF. The page downloads the PDF and closes itself
 * once it's made, so closing is switched off.
 */
async function pdfDocument(page: Page, projectId: number, options: Record<string, string> = {}) {
  await page.addInitScript(() => {
    window.close = () => {};
    let pdfMake: any;
    Object.defineProperty(window, "pdfMake", {
      configurable: true,
      get: () => pdfMake,
      set: (value) => {
        const createPdf = value.createPdf;
        value.createPdf = (definition: any, ...rest: any[]) => {
          (window as any).pdfContent = JSON.stringify(definition.content);
          return createPdf.call(value, definition, ...rest);
        };
        pdfMake = value;
      },
    });
  });
  const params = new URLSearchParams({
    pdf: "true",
    type: "quote",
    id: String(projectId),
    finance: "on",
    prices: "on",
    discounts: "on",
    assets: "on",
    draft: "true",
    ...options,
  });
  await page.goto(`/project/projectInvoice.php?${params}`, { waitUntil: "load" });
  await page.waitForFunction(() => (window as any).pdfContent !== undefined);
  return page.evaluate(() => (window as any).pdfContent as string);
}

/** Shows every assignment on the project page's asset list */
async function openAssetList(page: Page) {
  await page.getByRole("link", { name: "Assets List" }).click();
  await page.locator("#asset-list-tab-thisinstance").click();
  await page.locator("#expandAll").click();
}

/** Runs something that saves over ajax and then reloads the page, and waits for the reload */
async function andReload(page: Page, action: () => Promise<void>) {
  await Promise.all([page.waitForEvent("load"), action()]);
}

test("assets can be grouped under custom quote headings, split between them, and note lines added", async ({ page, festival }) => {
  await page.goto(`/project/?id=${festival.projects.stageA}`, { waitUntil: "domcontentloaded" });
  await page.getByRole("link", { name: "Quote Layout" }).click();
  await expect(page.getByText("No headings yet")).toBeVisible();

  for (const heading of ["Audio", "Video"]) {
    await page.locator("#newQuoteSection").click();
    await page.locator(".bootbox-input-text").fill(heading);
    await andReload(page, () => page.getByRole("button", { name: "OK" }).click());
    await page.getByRole("link", { name: "Quote Layout" }).click();
  }
  const sections = page.locator("#quoteLayoutCard tr.quoteSection");
  await expect(sections).toHaveText([/Audio/, /Video/]);

  // Split the 10 mics: 6 under Audio, 4 under Video
  await openAssetList(page);
  await page.locator(`.assetAssignmentCheckbox[data-assetassignmentid="${festival.assignments.micsOnStageA}"]`).check();
  await page.locator("#setAssetAssignmentQuoteSection").click();
  await expect(page.getByText("Split Between Quote Headings")).toBeVisible();
  await page.locator(".quoteAllocationInput").nth(0).fill("6");
  await page.locator(".quoteAllocationInput").nth(1).fill("4");
  await andReload(page, () => page.getByRole("button", { name: "Save" }).click());

  // The screen goes under Video
  await openAssetList(page);
  await page.locator(`.assetAssignmentCheckbox[data-assetassignmentid="${festival.assignments.screenOnStageA}"]`).check();
  await page.locator("#setAssetAssignmentQuoteSection").click();
  await page.locator(".bootbox-input-select").selectOption({ label: "Video" });
  await andReload(page, () => page.getByRole("button", { name: "OK" }).click());

  await openAssetList(page);
  const micRow = page.locator(`#assetRow${festival.assignments.micsOnStageA}`);
  await expect(micRow).toContainText("Audio ×6");
  await expect(micRow).toContainText("Video ×4");

  // A priced note line, e.g. a sub-rental
  await page.getByRole("link", { name: "Quote Layout" }).click();
  await page.locator("#newQuoteLine").click();
  await page.locator("#quoteLineText").fill("Sub-rental: LED wall");
  await page.locator("#quoteLineSection").selectOption({ label: "Video" });
  await page.locator("#quoteLineQuantity").fill("2");
  await page.locator("#quoteLinePrice").fill("250");
  await andReload(page, () => page.getByRole("button", { name: "Save" }).click());
  await page.getByRole("link", { name: "Quote Layout" }).click();

  await expect(sections.filter({ hasText: "Audio" })).toContainText("£60.00");
  // 4 mics, the screen and the LED wall
  await expect(sections.filter({ hasText: "Video" })).toContainText("£590.00");
  await expect(page.locator("#quoteLayoutCard tr.quoteLine")).toContainText("2x Sub-rental: LED wall");

  // The note line's price counts towards the project, and the cached totals keep up
  expect(cachedEquipmentSubTotal(festival.projects.stageA)).toBe(65000);

  const pdf = await pdfDocument(page, festival.projects.stageA);
  expect(pdf).toContain('"text":"Audio"');
  expect(pdf).toContain('"text":"Video"');
  expect(pdf).toContain("6x Test Mic");
  expect(pdf).toContain("4x Test Mic");
  expect(pdf).toContain("2x Sub-rental: LED wall (£250.00 each)");
  expect(pdf).toContain("Video Total");
  expect(pdf).toContain("£590.00");
  // Nothing is left for the category headings
  expect(pdf).not.toContain('"text":"Conventionals"');
});

test("units can't be split between headings beyond what's assigned", async ({ page, festival }) => {
  const section = await api(page, "newSection.php", { projects_id: festival.projects.stageA, projectsQuoteSections_name: "Audio" });
  expect(section.result).toBe(true);

  const tooMany = await api(page, "allocate.php", {
    assetsAssignments_id: festival.assignments.micsOnStageA,
    [`allocations[${section.response.projectsQuoteSections_id}]`]: 11,
  });
  expect(tooMany.result).toBe(false);
  expect(tooMany.error.message).toContain("Only 10 units");

  // A section from another project can't be used
  const otherSection = await api(page, "newSection.php", { projects_id: festival.projects.stageB, projectsQuoteSections_name: "Audio" });
  const wrongProject = await api(page, "allocate.php", {
    "assetsAssignments[]": festival.assignments.micsOnStageA,
    projectsQuoteSections_id: otherSection.response.projectsQuoteSections_id,
  });
  expect(wrongProject.result).toBe(false);
});

test("deleting a priced note line takes it off the project's total", async ({ page, festival }) => {
  // Loading the project makes its finance cache
  await page.goto(`/project/?id=${festival.projects.stageB}`, { waitUntil: "domcontentloaded" });
  expect(cachedEquipmentSubTotal(festival.projects.stageB)).toBe(4000);

  const line = await api(page, "newLine.php", { projects_id: festival.projects.stageB, projectsQuoteLines_text: "Crew catering", projectsQuoteLines_quantity: 3, projectsQuoteLines_price: "12.50" });
  expect(line.result).toBe(true);
  expect(cachedEquipmentSubTotal(festival.projects.stageB)).toBe(7750);

  const edited = await api(page, "editLine.php", { projectsQuoteLines_id: line.response.projectsQuoteLines_id, projectsQuoteLines_quantity: 4 });
  expect(edited.result).toBe(true);
  expect(cachedEquipmentSubTotal(festival.projects.stageB)).toBe(9000);

  expect((await api(page, "deleteLine.php", { projectsQuoteLines_id: line.response.projectsQuoteLines_id })).result).toBe(true);
  expect(cachedEquipmentSubTotal(festival.projects.stageB)).toBe(4000);
});

test("a parent project's quote can include its sub-projects, after a summary page", async ({ page, festival }) => {
  await page.goto(`/project/?id=${festival.projects.parent}`, { waitUntil: "domcontentloaded" });
  // Shared costs sit on the parent project
  expect((await api(page, "newLine.php", { projects_id: festival.projects.parent, projectsQuoteLines_text: "Transport", projectsQuoteLines_price: "300" })).result).toBe(true);

  // The option is only offered on projects with sub-projects
  await page.reload({ waitUntil: "domcontentloaded" });
  await expect(page.locator('#pdfGenerate input[name="subProjects"]')).toHaveCount(1);
  await page.goto(`/project/?id=${festival.projects.stageA}`, { waitUntil: "domcontentloaded" });
  await expect(page.locator('#pdfGenerate input[name="subProjects"]')).toHaveCount(0);

  const alone = await pdfDocument(page, festival.projects.parent);
  expect(alone).not.toContain("Quotation Summary");
  expect(alone).not.toContain("Main Stage");

  const combined = await pdfDocument(page, festival.projects.parent, { subProjects: "on" });
  expect(combined).toContain("Quotation Summary");
  expect(combined).toContain("Festival (shared)");
  // Parent £300, Main Stage £150 (10 mics and the screen), Second Stage £40 (4 mics)
  expect(combined).toMatch(/Main Stage".*£150\.00/);
  expect(combined).toMatch(/Second Stage".*£40\.00/);
  expect(combined).toMatch(/Grand Total.*£490\.00/);
  // Followed by each project's own quote, with equipment not under a heading shown under its category as before
  expect(combined).toContain("Other Items");
  expect(combined).toContain("Transport");
  expect(combined).toContain('"text":"Conventionals"');
  expect(combined.match(/"text":"Main Stage"/g)?.length).toBe(1);
  expect(combined.match(/"text":"Second Stage"/g)?.length).toBe(1);
});

test("a parent project's Sub-Projects tab lists each sub-project with its totals", async ({ page, festival }) => {
  await page.goto(`/project/?id=${festival.projects.parent}`, { waitUntil: "domcontentloaded" });
  expect((await api(page, "newLine.php", { projects_id: festival.projects.parent, projectsQuoteLines_text: "Transport", projectsQuoteLines_price: "300" })).result).toBe(true);
  await page.reload({ waitUntil: "domcontentloaded" });

  // Next to the project's own tab, with how many there are
  const tabs = page.locator("#nav-bar .nav-link");
  await expect(tabs.nth(1)).toHaveText("Sub-Projects (2)");
  await tabs.nth(1).click();

  const rows = page.locator("#subProjectsCard tr.subProjectRow");
  await expect(rows).toHaveCount(3);
  await expect(rows.nth(0)).toContainText("Festival (this project - shared)");
  await expect(rows.nth(0)).toContainText("£300.00");
  await expect(rows.nth(1)).toContainText("Main Stage");
  await expect(rows.nth(1)).toContainText("Confirmed");
  await expect(rows.nth(1)).toContainText("£150.00");
  await expect(rows.nth(2)).toContainText("Second Stage");
  await expect(rows.nth(2)).toContainText("£40.00");
  await expect(page.locator("#subProjectsCard tfoot")).toContainText("£490.00");
  await expect(rows.nth(1).getByRole("link", { name: "Main Stage" })).toHaveAttribute("href", new RegExp(`/project/\\?id=${festival.projects.stageA}$`));

  // The combined quote can be started from here, with sub-projects already ticked
  await page.getByRole("button", { name: "Create Combined Quote" }).click();
  await expect(page.locator("#pdfGenerateModal .modal-title")).toHaveText("Create Quote");
  await expect(page.locator('#pdfGenerateModal input[name="subProjects"]')).toBeChecked();
});

test("the project list shows each sub-project's own total, not its parent's", async ({ page, festival }) => {
  // Opening a project makes its finance cache, which the list reads
  for (const id of [festival.projects.parent, festival.projects.stageA, festival.projects.stageB]) {
    await page.goto(`/project/?id=${id}`, { waitUntil: "domcontentloaded" });
  }
  expect((await api(page, "newLine.php", { projects_id: festival.projects.parent, projectsQuoteLines_text: "Transport", projectsQuoteLines_price: "300" })).result).toBe(true);

  await page.goto("/project/list.php", { waitUntil: "domcontentloaded" });
  const row = (id: number) => page.locator("tr", { has: page.locator(`a[href$="/project/?id=${id}"]`) });
  await expect(row(festival.projects.parent)).toContainText("£300.00");
  await expect(row(festival.projects.stageA)).toContainText("£150.00");
  await expect(row(festival.projects.stageA)).not.toContainText("£300.00");
  await expect(row(festival.projects.stageB)).toContainText("£40.00");
});
