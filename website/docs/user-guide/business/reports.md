---
sidebar_position: 40
title: Reports
---

# Reports

The Reports page (**Business → Reports**) holds printable reports on your business's projects, money and equipment. Each report can be:

- opened on screen, with a choice of period (this month, last quarter, your financial year to date, the last 12 months, or your own dates)
- printed, or saved as a PDF, on your business's letterhead - the logo, colour, page size and header and footer text from your document settings
- downloaded as CSV, or as an Excel workbook with a sheet for each table

:::note Permissions Required
FINANCE:REPORTS:VIEW - the financial, sales and asset return reports  
REPORTS:VIEW - utilisation, dead stock, asset register, maintenance and crew reports  
FINANCE:EXPENSES:VIEW - the overheads report and list  
FINANCE:EXPENSES:EDIT - adding, changing and deleting overheads  
MAINTENANCE_JOBS:EDIT:COST - recording what a repair cost
:::

## The reports

| Group | Report | What it shows |
|---|---|---|
| Profit & loss | Period close pack | Profit and loss, cash received, billed against collected, aged debtors and the next three months' pipeline, in one document |
| | Profit & loss | Revenue, direct costs, gross margin, repairs, depreciation, overheads and net profit, against the previous period and the same period last year |
| | Project profitability | The margin on each project once the cost of its sales, additional hire and staff lines is taken off |
| | Monthly revenue | Revenue by month, split into equipment, sales, additional hire and staff |
| | Overheads | Overheads by category and month |
| | Discounts given | How far below its rates equipment was hired out, by client, project manager and project |
| Cash & debtors | Cash received | Payments by month, payment method and client |
| | Aged debtors | What each client owes, by how overdue it is |
| | Billed against collected | What was earned each month next to what was paid |
| | Collections & write-offs | Projects in collections or written off |
| Sales & clients | Client statement | A statement of account for one client, to send them |
| | Client league table | Revenue by client, share of revenue, average job size and how long they take to pay |
| | Pipeline & forecast | Won work, opportunities and leads for the months ahead |
| | Win/loss | How many quotes became work, by project type and project manager |
| Assets | Asset return | Revenue each asset type earned against its value and its purchase cost |
| | Utilisation | The share of days each asset type spent on won projects |
| | Dead stock | Assets that haven't been out for months, and the value tied up in them |
| | Asset register | Everything held on a date, by category and location, with replacement and book values |
| Operations | Maintenance | Repairs by asset type, their cost, and what blocked assets could have earned |
| | Crew workload | Projects, days and roles for each person |

## How the figures are worked out

- **Revenue** is what won projects bill - projects whose status is at the Won stage of the sales pipeline, in project types with finance turned on. It counts on the day the project's delivery ends.
- A project's figures come from its Finance tab. A project that has never been opened hasn't had its finances worked out; the report lists any of these and can work them out for you.
- **Direct costs** are what the business pays for the sales, additional hire and staff lines it bills. Record a line's cost in the **Our cost** box when you add it, or with **change** under the line on the project's Finance tab. The client never sees it. Lines without a cost count as costing nothing, and the profit & loss warns you about them.
- **Depreciation** spreads an asset's purchase cost evenly over its life, from its purchase date. Set an asset's purchase cost and date on its **Purchase** tab (under Edit). Assets are depreciated over the period set in the finance settings unless they have their own. An asset that's archived has what's left of its value written off.
- **Repairs** are the costs recorded on maintenance jobs, counted when the job was opened.
- **Overheads** are costs that aren't part of any project - rent, vehicles, insurance, salaries. Record them on the **Record overheads** page.

## Settings

Your financial year, how long assets are depreciated over, and your payment terms are set in **Business → Users & Settings → Invoices** (the finance settings).
