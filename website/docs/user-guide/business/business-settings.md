---
sidebar_position: 33
title: Business Settings
---

# Business Settings

## Basic Settings
---
Basic settings cover a number of useful settings about your business, including business information and invoice settings.

:::note Permissions Required
BUSINESS:BUSINESS_SETTINGS:VIEW  
BUSINESS:BUSINESS_SETTINGS:EDIT  
:::

![Basic Settings](/img/tutorial/businesses/settings-basic.png)
*Business Settings*

The Basic Settings page includes the following information:
- Basic Business settings:
  - Name
  - Address 
  - Email Address
  - Phone Number
  - Website
- Business Logo
- Email Header
- Mass unit - whether masses are shown and entered in kilograms or pounds
- Invoice Footer - Note on the bottom of all generated invoices
- Generated Documents - see [below](#generated-documents)
- Cable length colours - assign a colour to each length for ease of identification
- Asset Statuses - used for asset dispatch
- Calendar Settings - configure how calendars are displayed across the dashboard

## Generated Documents
---
The Invoices settings page also controls how quotes, invoices and delivery notes are generated. A preview beside the settings shows one of your projects' documents as you change them, before anything is saved.

- **Page size** - A4 or US Letter.
- **Dates and times** - how they are written, such as "31 Jan 2030" or "Jan 31, 2030", and 12 or 24 hour times.
- **Logo** - left, centre or right, and small, medium or large.
- **Accent colour** - used for the heading bands and a rule under the title.
- **Page header and footer text** - the text at the top left (your business's name by default) and bottom right of each page, and whether they are shown on the first page too.

For each type of document you can also set:

- **What it is called** - for example, a delivery note can be called a "Manifest", or a quotation an "Estimate". The name is used on the PDF and on the buttons that create it.
- **Reference** - what is printed as its reference. `{project}` is the project's number and `{version}` counts up each time the document is generated, so `INV-{project}-{version}` gives "INV-1042-2".
- **Opening text** - a paragraph at the start of the document.
- **Cover page text** - printed on the cover page, when one is asked for. Tick **Cover Page** when creating a document to start it with a page showing your logo, the project, who it was prepared for and by, the date and this text. Save that as a default to have one on every quote, say.
  The cover page can instead show only your logo and your text, aligned as you choose. The cover page text, opening text and footers can be formatted: `# Heading`, `## Smaller heading`, `**bold**`, `*italic*`, `- list item`, `---` for a line, `===` to start a new page, and an empty line between paragraphs. `{project}`, `{client}`, `{business}`, `{manager}`, `{managerEmail}`, `{document}`, `{date}`, `{number}` and `{reference}` are swapped for the project's details.
- **Signature line** - a line to sign and date at the end, with the label you give it, such as "Accepted by".
- **Footer** - the quotation and invoice footers, and one for delivery notes.

Under **Wording**, any of the words printed on the documents ("Our Reference", "Equipment", "Grand Total" and so on) can be changed.

### Default options
When creating a document from a project, a preview beside the options shows it as you tick and untick them. To keep a set of options for next time, click **Save these options as my default** - or, with permission to edit the business's settings, **Save these options as the default for everyone**. Defaults are saved separately for quotes, invoices and delivery notes; your own go on top of the business's.

## Calendar Settings
---
Calendar settings allow you to customize how calendars are displayed across the AdamRMS dashboard.

:::note Permissions Required
BUSINESS:BUSINESS_SETTINGS:VIEW
BUSINESS:BUSINESS_SETTINGS:EDIT
:::

The Calendar Settings page includes:
- **Show Project Statuses**: Displays the status of each project alongside its name on the calendar.
- **Show SubProjects**: Choose whether to display subprojects as individual entries on the calendar.
- **Default Calendar View**: Set the default view (Month, Week, Day, or List) for the business calendar.
- **Custom Week Labels**: Enable or disable the use of custom labels for weeks.

### Week Labels
Calendars across AdamRMS usually use the week number of the year as the week number, but this can be overwritten on a week-by-week basis.

![Week Labels](/img/tutorial/businesses/settings-weekLabels.png)

The date of each label can be any date in the required week. If two labels for the same week are found, the one lower in the list will be used.

## Public Site
---
AdamRMS supports provides a number of widgets to business, to allow information about business assets to be shared publicly.  
For example, this can be used to show a list of vacancies for crew roles.

:::note Permissions Required
BUSINESS:BUSINESS_SETTINGS:VIEW  
BUSINESS:BUSINESS_SETTINGS:EDIT  
:::

![Public site settings](/img/tutorial/businesses/settings-publicSite.png)
*AdamRMS public site settings*

Currently, there is one widget available:

- **Crew Vacancies** - This shows a list of crew roles that are currently vacant.

You can enable different pieces of information to be shared from the Public site settings page for each of these widgets.

## Project Types
---
Project types are a way to change what elements of [Projects](./../projects/overview) are available to each project. AdamRMS provides a “Full Project” by default which has all elements enabled.

:::note Permissions Required
PROJECTS:PROJECT_TYPES:VIEW  
PROJECTS:PROJECT_TYPES:CREATE  
PROJECTS:PROJECT_TYPES:EDIT  
PROJECTS:PROJECT_TYPES:DELETE  
:::

![Custom project types](/img/tutorial/businesses/settings-types.png)
*Project types*

Each Project is split into the following sections that can be enabled or disabled on a Project Type basis:
- Finance - Should asset charge calculations occur, and is billing enabled.
- Files - Can projects have files associated with them
- Assets - Assign assets to projects
- Client Assignment - Is this project associated with a client or an internal event.
- Venue - Associate a venue with this project.
- Notes - Use notes and comments in the project
- Crew - Use Crew assignment and recruitment in this project.

## Project Statuses
---
Project statuses allow you to track the progress of a project. You can define custom statuses to fit your business workflow.

:::note Permissions Required
PROJECTS:PROJECT_STATUSES:VIEW
PROJECTS:PROJECT_STATUSES:CREATE
PROJECTS:PROJECT_STATUSES:EDIT
PROJECTS:PROJECT_STATUSES:DELETE
:::

Each project status has the following properties:
- **Name**: The name of the status (e.g., Draft, Confirmed, Cancelled).
- **Description**: A short description of what the status means.
- **Icon**: An icon to represent the status in lists and calendars.
- **Colours**: Custom foreground and background colours for the status label.
- **Release Assets?**: If enabled, assets assigned to projects with this status are released and become available for other projects. This also removes the project from calendars and releases assigned crew.

## Asset Statuses
---
Asset statuses are used to track individual assets during a project, particularly during the dispatch and return process.

:::note Permissions Required
BUSINESS:BUSINESS_SETTINGS:VIEW
BUSINESS:BUSINESS_SETTINGS:EDIT
:::

Each asset status includes:
- **Name**: The name of the status (e.g., In Warehouse, Dispatched, On Site, Returned).
- **Description**: A short description of the status.
- **Rank**: The order in which statuses appear.
- **Availability**: Whether assets with this status are considered available or unavailable for other projects.

## Custom Categories
---
All Assets have an associated category that groups assets. AdamRMS comes with 29 sub-categories by default, but you can add new subcategories on a business basis.

:::note Permissions Required
ASSETS:ASSET_CATEGORIES:VIEW  
ASSETS:ASSET_CATEGORIES:CREATE  
ASSETS:ASSET_CATEGORIES:EDIT  
ASSETS:ASSET_CATEGORIES:DELETE  
:::

![Custom asset categories](/img/tutorial/businesses/settings-category.png)
*Asset Categories*

All subcategories have:
- A Name
- An Icon ([AdamRMS uses the Font Awesome Icon Set](https://fontawesome.com/v5.15/icons?d=gallery&p=2&m=free))
- A parent Category.

If you want to add a new parent category, please contact AdamRMS support.
