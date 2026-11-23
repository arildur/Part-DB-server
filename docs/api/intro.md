---
title: Introduction
layout: default
parent: API
nav_order: 1
---

# Introduction

Part-DB provides a [REST API](https://en.wikipedia.org/wiki/REST) to programmatically access the data stored in the
database.
This allows external applications to interact with Part-DB, extend it or integrate it into other applications.

{: .warning }
> This feature is currently in beta. Please report any bugs you find.
> The API should not be considered stable yet and could change in future versions, without prior notice.
> Some features might be missing or not working yet.
> Also be aware, that there might be security issues in the API, which could allow attackers to access or edit data via
> the API, which
> they normally should not be able to access. So currently you should only use the API with trusted users and trusted
> applications.

Part-DB uses [API Platform](https://api-platform.com/) to provide the API, which allows for easy creation of REST APIs
with Symfony and gives you a lot of features out of the box.
See the [API Platform documentation](https://api-platform.com/docs/core/) for more details about the API Platform
features and how to use them.

## Enable the API

The API is available under the `/api` path, but not reachable without proper permissions.
You have to give the users, which should be able to access the API the proper permissions (Miscellaneous -> API).
Please note that there are two relevant permissions, the first one allows users to access the `/api/` path at all and show the documentation,
and the second one allows them to create API tokens which are needed for the authentication of external applications.

## Authentication

To use API endpoints, the external application has to authenticate itself, so that Part-DB knows which user is accessing
the data and
which permissions the application should have. Basically, this is done by creating an API token for a user and then
passing it on every request
with the `Authorization` header as bearer token, so you add a header `Authorization: Bearer <your token>`.

See [Authentication chapter]({% link api/authentication.md %}) for more details.

## API endpoints

The API is split into different endpoints, which are reachable under the `/api/` path of your Part-DB instance (
e.g. `https://your-part-db.local/api/`).
There are various endpoints for each entity type (like `parts`, `manufacturers`, etc.), which allow you to read and write data, and some special endpoints like `search` or `statistics`.

For example, all API endpoints for managing categories are available under `/api/categories/`. Depending on the exact
path and the HTTP method used, you can read, create, update or delete categories.
For most entities, there are endpoints like this:

* **GET**: `/api/categories/` - List all categories in the database (with pagination of the results)
* **POST**: `/api/categories/` - Create a new category
* **GET**: `/api/categories/{id}` - Get a specific category by its ID
* **DELETE**: `/api/categories/{id}` - Delete a specific category by its ID
* **PATCH**: `/api/categories/{id}` - Update a specific category by its ID. Only the fields which are sent in the
  request are updated, all other fields are left unchanged.
  Be aware that you have to set the [JSON Merge Patch](https://datatracker.ietf.org/doc/html/rfc7386) content type
  header (`Content-Type: application/merge-patch+json`) for this to work.

A full (interactive) list of endpoints can be displayed when visiting the `/api/` path in your browser, when you are
logged in with a user, which is allowed to access the API.
There is also a link to this page, on the user settings page in the API token section.
This documentation also lists all available fields for each entity type and the allowed operations.

## Formats

The API supports different formats for the request and response data, which you can control via the `Accept`
and `Content-Type` headers.
You should use [JSON-LD](https://json-ld.org/) as format, which is basically JSON with some additional metadata, which
allows you to describe the data in a more structured way and also allows to link between different entities. You can achieve this
by setting `Accept: application/ld+json` header to the API requests.

To get plain JSON without any metadata or links, use the `Accept: application/json` header.

Without an `Accept` header (e.g. when you call the endpoint in a browser), the API will return an HTML page with the
documentation, so be sure to include the desired `Accept` header in your API requests.
If you can not control the `Accept` header, you can add a `.json` or `.jsonld` suffix to the URL to enforce a JSON or
JSON-LD response (e.g. `/api/parts.jsonld`).

## OpenAPI schema

Part-DB provides a [OpenAPI](https://swagger.io/specification/) (formally Swagger) schema for the API
under `/api/docs.json` (so `https://your-part-db.local/api/docs.json`).
This schema is a machine-readable description of the API, which can be imported into software to test the API or even
automatically generate client libraries for the API.

API generators which can generate a client library for the API from the schema are available for many programming
languages, like [OpenAPI Generator](https://openapi-generator.tech/).

An JSONLD/Hydra version of the schema is also available under `/api/docs.jsonld` (
so `https://your-part-db.local/api/docs.jsonld`).

## Interactive documentation

Part-DB provides an interactive documentation for the API, which is available under `/api/docs` (
so `https://your-part-db.local/api/docs`).
You can pass your API token in the form on the top of the page, to authenticate yourself, and then you can try out the
API directly in the browser.
This is a great way to test the API and see how it works, without having to write any code.

## Pagination

By default, all list endpoints are paginated, which means only a certain number of results is returned per request.
To get another page of the results, you have to use the `page` query parameter, which contains the page number you want
to get (e.g. `/api/categories/?page=2`).
When using JSONLD, the links to the next page are also included in the `hydra:view` property of the response.

To change the size of the pages (the number of items in a single page) use the `itemsPerPage` query parameter (
e.g. `/api/categories/?itemsPerPage=50`).

See [API Platform docs](https://api-platform.com/docs/core/pagination) for more infos.

## Filtering results / Searching

When retrieving a list of entities, you can restrict the results by various filters. Almost all entities have a search
filter, which allows you to only include entities, which (text) fields match the given search term: For example, if you only want
to get parts, with the Name "BC547", you can use `/api/parts.jsonld?name=BC547`. You can use `%` as a wildcard for multiple
characters in the search term (Be sure to properly encode the search term, if you use special characters). For example, if you want
to get all parts, whose name starts with "BC", you can use `/api/parts.jsonld?name=BC%25` (the `%25` is the url encoded version of `%`).

There are other filters available for some entities, allowing you to search on other fields, or restricting the results
by numeric values or dates. See the endpoint documentation for the available filters.

## Filter by associated entities

To get all parts with a certain category, manufacturer, etc. you can use the `category`, `manufacturer`, etc. query
parameters of the `/api/parts` endpoint.
They are so-called entity filters and accept a comma-separated list of IDs of the entities you want to filter by.
For example, if you want to get all parts with the category "Resistor" (Category ID 1) and "Capacitor" (Category ID 2),
you can use `/api/parts.jsonld?category=1,2`.

Suffix an id with `+` to suffix, to include all direct children categories of the given category. Use the `++` suffix to
include all children categories recursively.
To get all parts with the category "Resistor" (Category ID 1) and all children categories of "Capacitor" (Category ID
2), you can use `/api/parts.jsonld?category=1,2++`.

See the endpoint documentation for the available entity filters.

## Meta parts and connected parts

A part is either a manufacturing part (one concrete product of one manufacturer, as parts have always been) or a meta
part (a part definition that several manufacturing parts can meet). See [Meta parts](../usage/meta_parts.md) for the
concept.

* The `kind` field of a part is `manufacturing` (the default) or `meta`. It can be set when a part is created and is
  fixed afterwards: sending another kind in a PATCH is refused.
* Use the `kind` query parameter to list only one kind, for example `/api/parts.jsonld?kind=meta`. Without it, parts of
  both kinds are returned.
* A meta part has no manufacturer, manufacturer part number or orderdetails of its own.
* For a meta part, `total_instock` is the stock of its own lots plus the stock of the lots of its usable connected
  parts. `own_instock` and `partLots` only cover the part's own lots.
* The `part_connections` field of a meta part lists its connected manufacturing parts, in the order they are used.
* The `price_method` field of a meta part says how its price is worked out: `system_default` (the default for a new
  meta part, following the server setting), `primary_source`, `cheapest`, `average`, `weighted_by_stock` or
  `user_value`. Any other value is answered with 400. A manufacturing part reads `null`.
* `user_price` is a fixed unit price as a decimal string, in the currency `user_price_currency` (`null` for the base
  currency). The method `user_value` uses it, and it is kept as written whatever the method; `null` clears it.
* Setting `price_method` (any method, `system_default` too) or `user_price` on a manufacturing part is answered with 422.
* The response for a single meta part holds `price`: its unit price for one unit by its price method, in the base
  currency, or `null` when it has none. It is worked out on each read and can not be written; lists do not hold it.
* The `build_part` of a project can be a meta part as well as a manufacturing part. A build part, BOM entry or connection
  through which a project would reach its own build part, or that of a parent project, is answered with 422.

The connections themselves are a resource of their own at `/api/part_connections`:

| Field | Meaning |
|---|---|
| `meta_part`, `connected_part` | The meta part and the manufacturing part it is connected to, as IRIs. Set when the connection is created and fixed afterwards. |
| `position` | The order of priority, lowest first. When it is omitted, the connection is put last. |
| `status` | `approved` (the default), `under_evaluation`, `use_up` or `do_not_use`. Only the stock of a part connected as `approved` or `use_up` is counted for the meta part. Any other value is answered with 400. |
| `note` | A free text. |

Connections can be filtered by `meta_part`, `connected_part` and `status`, and ordered by `position`. Creating, changing
or deleting a connection needs the right to edit the meta part. A connection is refused if it would connect anything
other than a manufacturing part, or connect the same two parts twice.

## One part once per BOM

A part appears at most once in the BOM of a project. `POST` and `PATCH /api/project_bom_entries` of an entry whose project
already has another entry with the same part are answered with `422`, the violation at `part`. Entries without a part are
not compared, and names are not compared. The same part in the BOM of another project is allowed. A part stored twice in a BOM before this check keeps working; a `PATCH` of either entry that keeps
the part is refused while the other entry has it.

## Deleting a part used in a BOM

While the setting "Refuse deleting parts used in a BOM" is on, `DELETE /api/parts/{id}` of a part that a BOM entry points to
is answered with `409 Conflict` and deletes nothing; the `detail` of the error names the projects, or gives only their
number to a user without the permission to read projects. A user without the right to
delete the part still gets `403` first. While it is off (the default), the part is deleted and its BOM entries keep only its name.

The same holds for `DELETE /api/projects/{id}` of a project whose build part another project's BOM uses, as deleting a
project deletes its build part.

## Ordering results

When retrieving a list of entities, you can order the results by various fields using the `order` query parameter.
For example, if you want to get all parts ordered by their name, you can use `/api/parts/?order[name]=asc`. You can use
this parameter multiple times to order by multiple fields.

See the endpoint documentation for the available fields to order by.

## Property filter

Sometimes you only want to get a subset of the properties of an entity, for example when you only need the name of a
part, but not all the other properties.
You can achieve this using the `properties[]` query parameter with the name of the field you want to get. You can use
this parameter multiple times to get multiple fields.
For example, if you only want to get the name and the description of a part, you can
use `/api/parts/123?properties[]=name&properties[]=description`.
It is also possible to use these filters on list endpoints (get collection), to only get a subset of the properties of
all entities in the collection.

See [API Platform docs](https://api-platform.com/docs/core/filters/#property-filter) for more info.

## Change comment

Similar to the changes using Part-DB web interface, you can add a change comment to every change you make via the API,
which will be
visible in the log of the entity.

You can pass the text for this via the `_comment` query parameter (beware of the proper encoding). For
example `/api/parts/123?_comment=This%20is%20a%20change%20comment`.

## Creating attachments and parameters

To create attachments and parameters, use the POST endpoint. Internally there are different types of attachments and
parameters, for each entity type, where the attachments or parameters are used (e.g. PartAttachment for parts, etc.).
The type of the attachment or parameter is automatically determined by the `element` property of the request data if a
IRI is passed. You can use the `_type` property to explicitly set the type of the attachment or parameter (the value must
be the value of the `@type` property of the owning entity. e.g. `Part` for parts).

For example, to create an attachment on a part, you can use the following request:  

```
POST /api/attachments

{
    "name": "front68",
    "attachment_type": "/api/attachment_types/1",
    "url": "https://invalid.invalid/test.url",
    "element": "/api/parts/123"
}
```

## Uploading files to attachments

To upload files to the attachments you can use the special `upload` property of the attachment entity during write operations (POST, PUT, PATCH).
Under `data` you can pass a base64 encoded string of the file content, and under `filename` the name of the file.
Using the `private` property you can control if the file is the attachment should be stored privately or public.

For example, to upload a file to an attachment, you can use the following request:

```
PATCH /api/attachments/123

{
  "upload": {
    "data": "data:@file/octet-stream;base64,LS0gcGhwTXlB[...]",
    "filename": "test.csv",
    "private": false
  },
  "name": "Rename attachment"
}
```

This also works for creating new attachments, by including the `upload` property in the request data along with the other properties.

Using the `downloadUrl` property of `upload` you can say Part-DB to upload the file specified at the URL set on the attachment.

```
PATCH /api/attachments/123

{
  "upload": {
    "downloadUrl": true
  },
  "url": "https://host.invalid/myfile.pdf"
}

```