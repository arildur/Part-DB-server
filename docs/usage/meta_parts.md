---
title: Meta parts
layout: default
parent: Usage
---

# Meta parts

A part in Part-DB has a kind, which you choose when you create it and which only the conversion tools can change
afterwards (see [Converting between the kinds](#converting-between-the-kinds)).

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

## Converting between the kinds

The Tools tab of a part offers the conversions its kind allows. Each opens a page that shows what will be moved, copied
and kept, with the number of lots, suppliers, BOM entries and connections, and nothing changes until you click "Convert".
A conversion is saved in one step, or not at all if any part of it is not valid, and the history of each changed part
says why it changed.

**Convert to use a meta part** (on a manufacturing part, needs the permissions to edit and to create parts) asks which
record keeps the part's ID, and with it its BOM entries, labels, history, IPN and associations:

* **The part stays the manufacturing part**: A new meta part is created above it, with copies of its name, description,
  category, footprint, tags, comment, measurement unit, minimum stock, EDA fields, parameters and attachments, and the
  part connected to it at priority 1 as approved. Existing BOM entries keep requiring this exact part. Use this to add a
  second source to a part.
* **The part becomes the meta part**: Its manufacturer, part number, product URL, manufacturing status, GTIN, info
  provider reference, stock lots (keeping their IDs), suppliers and a manual order move to a new manufacturing part, which gets copies of
  the same descriptive fields (with a minimum stock of 0), parameters and attachments and is connected to it at priority 1
  as approved. Connections to other meta parts move to the new part with their priority, status and note. Existing BOM
  entries then accept the alternatives at once. Use this for a part already used as a generic part.

The new part gets no IPN. Afterwards you are back on the part that kept the ID, and a message names the new part with its
ID. A lot moves also when its storage location is marked as full, as moving adds nothing to it. The other rules of the
location still apply: a location limited to existing parts, or to a single part, refuses the lot of the new part, and
then nothing is converted and the message says why.

**Convert back to a manufacturing part** (on a meta part, needs the permission to edit it) only works on a meta part with
no connected parts: while it has any, the button is disabled and lists them, so remove these connections first. The part
keeps its ID, own lots, BOM entries and history; its price method is reset to system default and its user price removed.

Conversions are only available in the web interface, not over the REST API or MCP.

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

Merging two meta parts moves the connections to the target, a pair connected on both keeping the stricter status, and cloning a meta part copies them.
Merging two manufacturing parts moves the connections of the removed part to the target; if the target then has another
measurement unit than such a meta part, the merge form says so and saves nothing until you change the unit or cancel. Deleting a meta part leaves
its connected parts, and deleting a connected part removes it from its meta parts.

With the setting "Refuse deleting parts used in a BOM" (System settings > Behavior, off by default) Part-DB refuses to delete a part
that a BOM entry points to, a meta part or a manufacturing part, and names the projects; the delete button of the part page is then
disabled. Only a BOM entry that names the part itself counts: a connected part used only through its meta part can still be deleted,
and it is removed from its meta parts as above. Deleting a project deletes its build part, so deleting a sub-project whose build
part is in the BOM of another project, normally its parent, is refused the same way; deleting the parent together with its
sub-projects is not. With the setting off, the BOM entries of a deleted part keep only its name.

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

The Projects tab of a manufacturing part lists the BOM entries that use it directly, and after them those that use a
meta part it is connected to, with that meta part in the column "Through meta part". Connections of every status count;
a row reached through a part that is not usable is greyed, or red for "Do not use", and shows the status.

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

* **[Import and export](import_export.md)**: Files have a `kind` field and, at every level, the number and list of linked
  parts, which an import ignores: it does not create connections.
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

## Going back to a version without meta parts

The database can be rolled back to the version before meta parts. Meta parts without connections need nothing: when the
rollback drops the kind of parts, they become ordinary parts, keeping their ID, lots, BOM entries, parameters,
attachments and history. Their price methods and user prices are lost. The rollback warns with how many parts each of
these affects.

The rollback refuses while connections exist, so remove them first with a console command, which asks for each meta part
with connections what to do:

1. Make a backup, for example with `php bin/console partdb:backup --full backup.zip`.
2. Run `php bin/console partdb:meta-parts:prepare-rollback`. Add `--dry-run` to see what it would do without changing
   anything. For each meta part it shows the projects using it, its own lots and its connections in the order of
   priority, and asks:
    * **keep** (the default, and the answer under `--no-interaction`): only the connections are removed. The part stays
      and becomes an ordinary part after the rollback. The connected parts are not touched.
    * **replace**: every BOM entry of the meta part points at one of its connected parts instead, keeping quantity, mount
      names and comment, and the meta part is deleted. The primary source (the first usable connection) is offered first.
      This is refused while the meta part has lots of its own, is the build part of a project, or a BOM using it already
      has an entry for the chosen part; the command says why and asks again.
    * **skip**: nothing changes for now, and the rollback stays refused.

   Each part is saved on its own, and the history of each changed part says why it changed. At the end the command
   names the rollback command when no connection is left.
3. Roll back with `php bin/console doctrine:migrations:migrate 'DoctrineMigrations\Version20260907090000'`, then install
   the earlier version.

The command needs the database at the latest version. If a rollback was started before and stopped at the connections,
the later migrations have already been rolled back: run `php bin/console doctrine:migrations:migrate` first, then the
command and the rollback.
