---
sidebar_position: 18
title: Unserialized Assets
---

# Unserialized Assets

Most assets in AdamRMS are **serialized**: one asset record stands for one physical item, with its own tag, barcode and history. That is the right model for a moving light or a mixing desk, where it matters which one went out on which job.

Some stock isn't worth tracking that way. Gel frames, cable ties, safety bonds and sandbags are interchangeable, and nobody needs to know which particular one came back. For those, create an **unserialized** asset: a single asset record that holds a quantity of identical units.

:::note Permissions Required
ASSETS:CREATE  
ASSETS:EDIT  
PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN — to assign units to a project or change how many a project takes  
:::

## Creating an unserialized asset
---

On the [New Asset](./new-assets) page, pick the asset type as usual, then tick **Unserialized asset** and enter the **Quantity Held** — the number of units your business owns.

An unserialized asset still gets a tag and a barcode, so you can label the box or shelf the units live on.

To convert an existing asset, open it and use the **Edit** tab: the same tick box and quantity field are there.

## How quantities work on projects
---

When you add an unserialized asset to a project you are asked how many units that project needs. Adding a serialized asset is unchanged — it takes the one unit that exists.

Availability is worked out per unit. If you hold 50 gel frames and two overlapping projects have taken 30 and 15, then 5 are left; a third project that asks for 10 is told only 5 are available. As with serialized assets, projects whose status releases their assets don't hold anything back.

An asset appears on a project once, however many units it takes. The project's asset list shows the count as a blue badge — `12x` — next to the asset tag. Click the badge to change how many units the project needs, rather than removing and re-adding the asset.

Mass, value and hire price are all held **per unit** and multiplied by the number of units taken, so a project holding 12 units of a 0.2&nbsp;kg item carries 2.4&nbsp;kg for it. This applies to custom prices set on an assignment too: the price you enter is the price of one unit.

## Reducing the quantity held
---

You can't reduce the quantity held below the number of units a current project has already taken — remove them from that project first. Reducing the quantity has no effect on projects that have already finished.

## Limitations
---

- Units of an unserialized asset can't be tracked individually. There is one barcode, one storage location and one maintenance history for the whole asset, not one per unit.
- The asset import spreadsheet always creates serialized assets. Tick **Unserialized asset** on the asset afterwards to set a quantity.
