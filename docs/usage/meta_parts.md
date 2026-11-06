---
title: Meta parts
layout: default
parent: Usage
---

# Meta parts

A part in Part-DB has a kind, which you choose when you create it and which is fixed afterwards.

* A **manufacturing part** is one product of one manufacturer, like `RC0603FR-0710KL`. All parts are manufacturing parts
  unless you choose otherwise, and they work as they always did.
* A **meta part** is a specification, like "10k resistor, 0603, 1%". You connect to it the manufacturing parts that
  fulfill it and can replace each other. You can then use the meta part in a schematic or a BOM without deciding yet
  which product goes on the board.

## Creating a meta part

Use "New meta part" in the tools tree, the navigation bar or the part list. The new part form also has a field for the
kind, and shows only what that kind allows. A part keeps its kind when it is cloned, and the kind can only be chosen on a
blank form.

A meta part can have a category, footprint, IPN, parameters, attachments and stock lots of its own. It can not have:

* a manufacturer, manufacturer part number, product URL, manufacturing status, GTIN or info provider reference
* suppliers, which belong to its connected parts
* a project that builds it

Parts of different kinds can not be merged, and a meta part can not be updated from an info provider.

## Connecting parts

Connections are edited on the edit page of the meta part, which needs the permission to edit it. Each connection has:

* **Priority**: The lowest comes first. Leave it empty to add the part at the end.
* **Status**: "Approved", or "Do not use" for a part that should stay listed but not be used.
* **Note**: Anything worth knowing about this part in this role.

Only manufacturing parts can be connected, and each only once to the same meta part. A connected part must have the same
measurement unit as the meta part. Part-DB warns you when its footprint differs, but allows it. One part can be connected
to several meta parts, with its own priority and status under each, and its page lists them.

Merging two meta parts moves the connections to the target, and cloning a meta part copies them. Deleting a meta part leaves
its connected parts, and deleting a connected part removes it from its meta parts.

Changes to connections are logged on both parts. Time travel and undo do not follow connections, as that would rewrite the
stock of other parts, so an old version of a meta part shows the current stock of its connected parts.

## Stock and prices

The stock of a meta part is the sum of its own lots and the lots of its usable connected parts. As with any part, lots of
unknown amount and expired lots do not count. The own lots of a meta part never count for its connected parts.

* The **Stock** tab lists its own lots and those of its connected parts with a total, and marks what is not counted. You
  can only change its own lots there: to withdraw from another part, use the page of that part.
* The **Shopping** tab lists the suppliers of each connected part, and the summary shows the price range.
* In part tables, the amount, its sorting and its filter use the total. The storage location column includes the usable
  connected parts, while the filters on lots, including the storage location filter, only look at a part's own lots.

## Projects

A BOM entry can point at a meta part. When building, the form names the parts below the meta part, and you choose how much
to take from each lot. Parts marked "Do not use" have no field. Several entries can reach the same lot, so the proposal and
the buildable count look at the whole BOM, and a lot can not be used for more than it holds.

A project has a setting for what a meta part costs in its BOM:

* **Primary source**: The price of the first connected part that has one.
* **Cheapest**: The lowest price of the connected parts.
* **Range**: The lowest and the highest price.

## Other parts of Part-DB

* **[Import and export](import_export.md)**: Files have a `kind` field. The level `full` lists connections, but an import
  does not create them.
* **[BOM import](bom_import.md)**: Rows can link different parts that have the same name, and such entries are saved
  without a name of their own. A row linked by a supplier part number links that manufacturing part, not a meta part.
* **[KiCad](eda_integration.md)**: Meta parts are listed like other parts, with their total stock.
* **[Labels](labels.md)**: Twig labels can read the kind and the connections.
* **[Scanner](scanner.md)**: The info mode shows the total of a meta part and a link to its stock.
* **Internal part numbers**: In the server settings, the IPN suggestions can have their own pattern and global prefix for
  meta parts. When these are empty, both kinds use the same settings. Both kinds share one set of unique numbers.
* **REST API**: `GET /api/parts?kind=meta` or `kind=manufacturing` filters by kind, and any other value is answered with a
  400. A part shows `kind`, `total_instock`, `own_instock` and `part_connections`, and connections are written through
  `/api/part_connections`.
* **[MCP](../api/mcp.md)**: The part tools take and show the kind and the connections.
