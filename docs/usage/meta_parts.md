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

Parts of different kinds can not be merged, and a meta part can not be updated from an info provider.

Part-DB finds a meta part by its name, so when you save a meta part with the name of another meta part (ignoring case
and outer spaces), it warns and names the other meta parts with their IDs. The part is saved all the same. A
manufacturing part with the same name gives no warning.

In part tables, in part pickers and in the search field, only meta parts are marked: the name of a meta part has a
sitemap icon in front of it. A manufacturing part is shown without a kind icon.

## Connecting parts

Connections are edited on the edit page of the meta part, which needs the permission to edit it. Each connection has:

* **Priority**: The lowest comes first. Leave it empty to add the part at the end.
* **Status**: "Approved" (the default) for a part that may be used, "Use up" for a part that may be used and whose stock
  should be used first, "Under evaluation" for a part not yet approved, or "Do not use" for a part that should stay listed
  but not be used. Only approved and use up parts are usable: their stock counts, builds can take from them and prices
  use them. The others are listed last on the pages of the meta part, marked with their status.
* **Note**: Anything worth knowing about this part in this role.

Only manufacturing parts can be connected, and each only once to the same meta part. A connected part must have the same
measurement unit as the meta part. Part-DB warns you when its footprint differs, but allows it. One part can be connected
to several meta parts, with its own priority and status under each, and its page lists them.

Merging two meta parts moves the connections to the target, a pair connected on both keeping the stricter status, and cloning a meta part copies them. Deleting a meta part leaves
its connected parts, and deleting a connected part removes it from its meta parts.

With the setting "Refuse deleting parts used in a BOM" (System settings > Behavior, off by default) Part-DB refuses to delete a part
that a BOM entry points to, a meta part or a manufacturing part, and names the projects; the delete button of the part page is then
disabled. Only a BOM entry that names the part itself counts: a connected part used only through its meta part can still be deleted,
and it is removed from its meta parts as above. With the setting off, the BOM entries of a deleted part keep only its name.

Changes to connections are logged on both parts. Time travel and undo do not follow connections, as that would rewrite the
stock of other parts, so an old version of a meta part shows the current stock of its connected parts.

## Searching

A search matches the fields of each part itself, for a meta part as for a manufacturing part: a meta part is not found
through the part number or other fields of its connected parts. The kind filter limits a search to one kind.

## Stock and prices

The stock of a meta part is the sum of its own lots and the lots of its usable connected parts. As with any part, lots of
unknown amount and expired lots do not count. The own lots of a meta part never count for its connected parts.

* The **Stock** tab lists its own lots and those of its connected parts with a total, and marks what is not counted. You
  can only change its own lots there: to withdraw from another part, use the page of that part.
* The **Shopping** tab lists the suppliers of each connected part, and the summary shows the price of the meta part for
  one unit, followed by its price method.
* In part tables, the amount, its sorting and its filter use the total. The storage location column includes the locations
  of every connected part, those of parts that are not usable last and greyed, while the filters on lots, including the storage location filter, only look at a part's own lots.

### Price

A meta part has one price, worked out on each read by its price method, which is chosen on the edit page of the meta
part:

* **System default**: Follows the default price method in the server settings (Miscellaneous, Meta parts), which is
  primary source until it is changed there or by the environment variable `META_PART_DEFAULT_PRICE_METHOD`. A new
  meta part starts with this method.
* **Primary source**: The price of the first usable connected part, in the order of priority, that has a price.
* **Cheapest**: The lowest price of the usable connected parts.
* **Average**: The average of the prices of the usable connected parts.
* **Weighted by stock**: The average of the prices of the usable connected parts, each weighted by its stock. The own
  lots of the meta part do not weigh. With no stock at all, it is the average.
* **User value**: A fixed unit price typed on the meta part, with its own currency, whatever the quantity.

A calculated price takes each connected part at the quantity asked for, with its bulk prices and minimum order, and
follows their prices and stock as they change. It can not be edited. The user value is only shown while the method is
user value, and is kept when another method is chosen. Merging two meta parts keeps the method of the target unless it
is system default, and takes the user value of the other only when the target has none.

## Projects

A BOM entry can point at a meta part. When building, the form names the parts below the meta part, and you choose how much
to take from each lot. Parts that are not usable are shown with their status and have
no field. The proposal takes the meta part's own lots first, then those of use up parts, then those of approved parts, each
in the order of priority, and within a part the smallest lot first, so that leftovers are used up. An entry pointing
straight at a part takes its largest lot first, as Part-DB always did. Lots of the same amount are taken in the order
they were created. Several entries can reach the same lot, so the proposal and
the buildable count look at the whole BOM, and a lot can not be used for more than it holds.

A meta part can also be the part a project builds: on the edit page of the project, add a build part and choose the
kind. When the built units are added to stock, the build form offers the meta part's own lots and the lots of each of its
connected parts, whatever their status, or a new own lot of the meta part. Chosen at first is an own lot, or else a lot
of an approved or use-up connected part, never one of the other statuses. A project can not reach its own build part, or
that of a parent project: its BOM can hold neither the build part, nor a meta part connected to it, nor a connected
part of a meta part that is the build part, nor a meta part sharing a connected part with it. This is checked on the BOM
entry, on the connection and on the build part.

The cost of a BOM entry pointing at a meta part is the price of the meta part at the quantity of the entry times the
number of builds. A meta part with no price leaves its entry blank, and the project page counts the entries with no price
next to the total cost.

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
  `/api/part_connections`. A meta part also has `price_method`, `user_price` and `user_price_currency`, and the
  response for a single meta part holds its read-only `price`. A project's `build_part` can be a meta part.
* **[MCP](../api/mcp.md)**: The part tools take and show the kind, the connections and the price method.
