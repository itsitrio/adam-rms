---
sidebar_position: 24
title: Project Finance
---

# Project Finance
---

Project finance allows you to track hire, crew and purchasing costs for each project.

:::note Permissions Required
PROJECTS:PROJECT_PAYMENTS:VIEW  
PROJECTS:PROJECT_PAYMENTS:CREATE  
PROJECTS:PROJECT_PAYMENTS:DELETE  
PROJECTS:EDIT:INVOICE_NOTES  
PROJECTS:PROJECT_PAYMENTS:VIEW:FILE_ATTACHMENTS  
PROJECTS:PROJECT_PAYMENTS:CREATE:FILE_ATTACHMENTS  
:::

![Finance Dashboard](/img/tutorial/projects/finance-dashboard.png)
*Finance Dashboard*

## Adding Costs
---

AdamRMS automatically calculates equipment hire costs, but you can add additional costs to a project in the following categories:
- Sub-Hires
- Staffing Costs
- Sales  

Each of these items requires the following information:  
- Quantity
- Supplier
- Description
- Amount

![Add staffing cost to a project](/img/tutorial/projects/finance-add.png)
*Adding Staffing cost to the project*

## Receiving Payments
---

You can record the receiving of payments in each project.  
A payment consists of the following information:

- Payment date
- Reference
- Payment Method:
  - Credit Card
  - Debit Card
  - Bank Transfer
  - Cash
  - Cheque
  - Other
- Description
- Amount received.

![Recording Payment](/img/tutorial/projects/payment-recieved.png)
*Adding a payment record*

## Quotes
---
Quotes are documents you can share with clients before a committing to a project. You can customise them to include as much information as you want.  
They can also be used as printouts of project assets.

The footer on the quote can be customised in the business settings (permission 83 is required for this).


![Creating an Quote](/img/tutorial/projects/finance-quote-new.png)
*Create a Quote*

Quotes are stored in the Invoices and Quotes tab, and count towards your business file quota.
Every generated quote is stored, so you can track the history of the project through quotes.

![A Sample Quote](/img/tutorial/projects/finance-quote.png)
*An example quotes*

### Quote Layout

By default a quote lists equipment under its asset categories. The **Quote Layout** tab on a project lets you use your own headings instead - for example Video, Audio, Lighting and Visuals for a stage - and add note lines. You need the `PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES` permission to change it.

- **Headings** are added, renamed, reordered and deleted from the Quote Layout tab.
- To **place assets under a heading**, select them in the Assets List and click the *Place in quote section* (layers icon) button. If you select a single asset that has several units (such as 10 microphones), you can split the units between headings - e.g. 6 under Audio and 4 under Video.
- Anything not placed under a heading is still shown under its asset category, after the headings.
- **Note lines** sit under a heading, or at the end of the quote. They can have a quantity and a price - useful for sub-rentals, transport or other items that aren't assets. A note line's price is added to the project's equipment total; leave the price empty for a plain note.

The layout also applies to invoices and delivery notes.

### Quoting Sub-Projects Together

When a project has sub-projects - such as a festival with a sub-project for each stage, and costs shared between the stages on the parent project - you can tick **Include Sub-Projects, with a Summary Page** when creating a quote or invoice for the parent project. The PDF then starts with a summary page listing the parent project and each sub-project with its dates and totals, and a grand total, followed by the parent project's quote and each sub-project's quote in turn.


## Invoices
---
Invoices are documents you can share with clients that sum up a project. You can customise them to include as much information as you want.  
They can also be used as printouts of project assets. 

The footer on the invoice can be customised in the business settings (permission 83 is required for this).


![Creating an Invoice](/img/tutorial/projects/finance-invoice-new.png)
*Create an Invoice*

Invoices are stored in the Invoices and Quotes tab, and count towards your business file quota.
Every generated invoice is stored, so you can track the history of the project through invoices.

![A Sample Invoice](/img/tutorial/projects/finance-invoice.png)
*An example invoice*

## Related Features

- [Ledger](../business/ledger) -- view all payments across the business in one place
- [Clients](../business/clients) -- view total paid and outstanding balances per client
- [Business Settings](../business/business-settings#basic-settings) -- customise invoice and quote footers
- [Project Assets](./assets) -- asset pricing, discounts, and custom prices that feed into the finance calculations