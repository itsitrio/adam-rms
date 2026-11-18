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
FINANCE:LIABILITIES:VIEW - the loans & finance report and pages  
FINANCE:LIABILITIES:EDIT - adding loans and finance, and recording repayments  
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
| | Loans & finance | What the business owes, what's been paid off, interest and fees, and the repayments due over the next year |
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
- **Interest and fees** on loans and finance are a cost in the profit & loss. Repaying what was borrowed isn't - see below.

## Loans & finance

Record what the business owes on the **Record loans & finance** page: a bank loan, equipment bought on finance or on credit from a supplier and paid off over time, or money lent by a supplier or a member of the business.

Each one starts at the amount borrowed on its start date. Then record, as they happen:

- **Repayments** - with how much of each was interest or a fee, from the lender's statement. That part is a cost; the rest pays off what's owed.
- **Interest or fees added** to the balance, if the lender adds them rather than taking them from a repayment.
- **More borrowed**, if the business draws down more.

Give it a regular repayment, how often it's paid and the date of the first one, and the page and the Loans & finance report show the repayments still to come. Link the assets it paid for - from the loan's page, or on each asset's Purchase tab - to see their book value against what's still owed. Buying an asset on finance is recorded twice: the asset's purchase cost on its Purchase tab (so it's depreciated), and the finance here (so the repayments and interest are tracked).

## Settings

Your financial year, how long assets are depreciated over, and your payment terms are set in **Business → Users & Settings → Invoices** (the finance settings).
