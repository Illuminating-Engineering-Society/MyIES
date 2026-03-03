FORMAT: 1A





# Wicket API

Welcome to the Wicket API! You can use our API to access Wicket API endpoints, which can get information on various people, orders, and associated membership details from the Wicket database.

Wicket's API is implemented following the JSON API specification. Please refer to the [documentation](http://jsonapi.org/) for details about the formatting of responses.

Our API provides predictable resource oriented urls for all end points following a [REST architecture](https://en.wikipedia.org/wiki/Representational_state_transfer). We use HTTP verbs to perform [CRUD operations](https://en.wikipedia.org/wiki/Create,_read,_update_and_delete) and proper [HTTP status codes](https://en.wikipedia.org/wiki/List_of_HTTP_status_codes) in responses, including errors.

All calls to the API should be made through HTTPS to your corresponding tenant's api url of the form:

```
https://<tenant>-api.wicketcloud.com
```

Use this documentation by first finding the resource type you are trying to query in the menu on the left and then find the appropriate operation to see detailed information about how to build your request and what to expect from the response.



## API Authentication

Wicket's API authentication uses [JSON Web Tokens](https://jwt.io/). Requests are authenticated by the `bearer` authorization scheme, passing a valid JWT token in the request header:

```
Authorization: Bearer <JWTtoken>
```

In order to generate JWT tokens you need to be provisioned with an API Secret Key and an API User [UUID](https://en.wikipedia.org/wiki/Universally_unique_identifier) with administrative privileges. To obtain these please contact our [customer support](https://support.wicket.io/).

Your API Secret Key and Admin User UUID combination grants you full unrestricted access over your Wicket account. It is very important to that you **keep your secret key safe!**. Make sure to never share it or place it in publicly accessible locations such as public code repositories, client side code etc.

Once you have these pieces of information, you can generate your JWT token to interact with Wicket's API. An introduction to how JWT tokens are created can be found [here](https://jwt.io/introduction/).

Wicket expects the following header:

```
{
  "alg": "HS256", //Encryption algorithm
  "typ": "JWT".   //Type
}
```

It also expects the following registered claims in the payload:

```
{
  "exp": 1534414138,                             //Unix Timestamp to for token expiration
  "sub": "87882d7d-c230-4385-9231-e4fe9393f626"  //UUID of your API admin user
  "aud": "https://<tenant>-api.wicketcloud.com", //Your Wicket API URL
  "iss": "https://your-website.com"              //Optional issuer domain
}
```
For better security, keep the expiration date of your JWT tokens as short as possible.

For calls to the API that involve accessing or updating data on behalf of a user, it is highly recommended to always use a token scoped for that user's person uuid. This will ensure API resources and corresponding actions are scoped to what that user has permissions to do. When using SSO through our implementation of [Central Authentication Service (CAS)](https://github.com/apereo/cas), the person ID is provided in the CAS attributes. Alternatively you can retrieve people's UUIDs from the People endpoint.

Using the API Admin user should be reserved for more special cases such as management operations or actions that shouldn't be scoped to just a specific end user.

You can find [helper libraries in various languages](https://jwt.io/#libraries-io) to help you generate JWT tokens for your specific integration with Wicket's API.

## Rate Limiting

The Wicket API implements rate limiting to ensure fair usage and protect service stability. Rate limits are enforced per authenticated user or API client, with a default limit of 500 requests per minute. If your integration requires different rate limits, please contact [customer support](https://support.wicket.io/) to discuss your specific needs.

### Rate Limit Headers

All API responses include headers to help you monitor your rate limit usage:

```
RateLimit-Limit: 500
RateLimit-Remaining: 499
RateLimit-Reset: 1640995200
```

- `RateLimit-Limit`: The maximum number of requests allowed in the current window
- `RateLimit-Remaining`: The number of requests remaining in the current window
- `RateLimit-Reset`: The Unix timestamp when the rate limit window resets

When rate limited (429 response), an additional header is included:
- `Retry-After`: Seconds until you can retry

### Rate Limit Exceeded Response

When you exceed the rate limit, the API returns a `429 Too Many Requests` status with a JSON:API error response:

```json
{
  "errors": [
    {
      "status": "429",
      "code": "rate_limit_exceeded",
      "title": "Too Many Requests",
      "detail": "Rate limit of 500 requests per 60 seconds exceeded",
      "meta": {
        "limit": 500,
        "period": 60,
        "retry_after": 45
      }
    }
  ]
}
```

### Best Practices

To work effectively within rate limits:

1. **Monitor rate limit headers** in responses to track your usage
2. **Implement retry logic** with exponential backoff when receiving 429 responses
3. **Cache responses** where appropriate to reduce redundant API calls
4. **Use efficient pagination** with reasonable page sizes
5. **Distribute requests** evenly rather than in bursts


## Fetch Sorting, Pagination, and Filtering

Every resource's Fetch `GET` request supports sorting, pagination, and filtering using the patterns outlined below.


### Sorting

Sorting is controlled using the sort parameter followed by the attribute name.

```
?sort=<attribute>
```
You may sort by multiple attributes using a comma-separated list.

```
?sort=<attribute1>,<attribute2>
```

By default all sorting is ascending.  To sort descending, prepend a minus sign '-' to the attribute name.

```
?sort=-<attribute>
```

The following example will sort people by their given_name ascending and then by their family_name descending.

```
/people?sort=given_name,-family_name
```

### Pagination

Pagination is controlled using the page[number] and page[size] query string parameters.  Paging respects the sort query parameters.

```
?page[number]=2&page[size]=10
```

The following example will retrieve people from page number 2 with 10 records per page. (Records 11 - 20)

```
/people?page[number]=2&page[size]=10
```

Pagination is enforced on almost all collection endpoints, the default page size is 25. Some endpoints may return 100-250 records by default but these typically depend on the performance characteristics of the data.

In most cases smaller page sizes <= 100 should be used as these can be load balanced more efficiently by our infrastructure. Unless otherwise noted the maximum page size is 2000.

As of March 2024, the /organizations endpoint now enforces pagination by default with a maximum page size of 500 and a default of 25 records (some existing tenants may have access to larger page sizes)

### Filtering

Filtering returns a subset of resources matching the provided criteria. It is controlled by the filter query string parameter with the attribute name and search matcher in square brackets followed by the filter's value.

```
?filter[<attribute>_<search_matcher_predicate>]=<value>
```

Attribute is the name of the entities attribute. Example: `updated_at`.

Search matcher predicates, like `eq` for equals, or `gteq` for greater than or equal, are documented in the table below.

```
?filter[updated_at_gteq]=2021-04-25T00:00:00+00:00
```


A common use for filters is to return all resources updated on or after a given DateTime. The following example returns all people updated on or after April 25, 2021.

```
/people?filter[updated_at_gteq]=2021-04-25T00:00:00+00:00
```

You can apply multiple filters on a single fetch request.
```
/people?filter[given_name_eq]=Joe&filter[family_name_eq]=Smith
```

For complex filtering use cases, the following endpoints support POST requests the filters provided in the request body as JSON.

```
/connections/query
/groups/query
/group_members/query
/organizations/query
/organization_memberships/query
/people/query
/person_memberships/query
```

**Example**

POST /people/query?include=emails,phones,addresses

```json
{
  "filter": {
    "connections_organization_uuid_eq": "73cd91c1-76e4-4421-84df-acf1452cb8bc",
    "connections_tags_name_eq": "Employee",
    "membership_people_membership_uuid_in": ["8ddce1e-6938-4216-9ce9-f24a6b7cd677"],
    "membership_people_status_eq": "Active",
    "search_query": {
      "_or": [
        {
          "data_fields.section.value.field": "value"
        },
        {
          "data_fields.section2.value.field2": "value"
        }
      ]
    }
  }
}
```

#### Filter Search Matcher Predicates

| Predicate | Description | Notes |
| ------------- | ------------- |-------- |
| `*_eq`  | equal  | |
| `*_not_eq` | not equal | |
| `*_matches` | matches with `LIKE` | e.g. `q[email_matches]=%@gmail.com`|
| `*_does_not_match` | does not match with `LIKE` | |
| `*_matches_any` | Matches any | |
| `*_matches_all` | Matches all  | |
| `*_does_not_match_any` | Does not match any | |
| `*_does_not_match_all` | Does not match all | |
| `*_lt` | less than | |
| `*_lteq` | less than or equal | |
| `*_gt` | greater than | |
| `*_gteq` | greater than or equal | |
| `*_present` | not null and not empty | e.g. `q[name_present]=1` (SQL: `col is not null AND col != ''`) |
| `*_blank` | is null or empty. | (SQL: `col is null OR col = ''`) |
| `*_null` | is null | |
| `*_not_null` | is not null | |
| `*_in` | match any values in array | e.g. `q[name_in][]=Alice&q[name_in][]=Bob` |
| `*_not_in` | match none of values in array | |
| `*_lt_any` | Less than any |  SQL: `col < value1 OR col < value2` |
| `*_lteq_any` | Less than or equal to any | |
| `*_gt_any` | Greater than any | |
| `*_gteq_any` | Greater than or equal to any | |
| `*_matches_any` | `*_does_not_match_any` | same as above but with `LIKE` |
| `*_lt_all` | Less than all | SQL: `col < value1 AND col < value2` |
| `*_lteq_all` | Less than or equal to all | |
| `*_gt_all` | Greater than all | |
| `*_gteq_all` | Greater than or equal to all | |
| `*_matches_all` | Matches all | same as above but with `LIKE` |
| `*_does_not_match_all` | Does not match all | |
| `*_not_eq_all` | none of values in a set | |
| `*_start` | Starts with | SQL: `col LIKE 'value%'` |
| `*_not_start` | Does not start with | |
| `*_start_any` | Starts with any of | |
| `*_start_all` | Starts with all of | |
| `*_not_start_any` | Does not start with any of | |
| `*_not_start_all` | Does not start with all of | |
| `*_end` | Ends with | SQL: `col LIKE '%value'` |
| `*_not_end` | Does not end with | |
| `*_end_any` | Ends with any of | |
| `*_end_all` | Ends with all of | |
| `*_not_end_any` | | |
| `*_not_end_all` | | |
| `*_cont` | Contains value | uses `LIKE` |
| `*_cont_any` | Contains any of | |
| `*_cont_all` | Contains all of | |
| `*_not_cont` | Does not contain |
| `*_not_cont_any` | Does not contain any of | |
| `*_not_cont_all` | Does not contain all of | |
| `*_true` | is true | |
| `*_false` | is false | |

## Integrate Wicket with a Website

We have plugins, services, and tools to make Wicket's integration with your website easy and user friendly.
### Single Sign On (SSO, OAuth, OpenID)

Wicket can be configured as an Single Sign On authentication provider with protocols like OAuth, SAML, and OpenID.
Single Sign On is not required to use Wicket's API but SSO is helpful in cases when Members sign in to your website or third-party service which interacts with the Wicket API.
See our [Getting Started with SSO and OAuth 2.0](https://support.wicket.io/hc/en-us/articles/4411663172631-Getting-Started-with-SSO-and-OAuth-2-0) guide to learn more.

### CMS, Wordpress and Drupal Integrations
Wicket's [Getting Started with CMS Integrations](https://support.wicket.io/hc/en-us/articles/360025699333-Getting-Started-with-CMS-Integrations)
guide outlines how to integrate Wicket with your CMS to create a Member Account Center. Wicket's set of Plugins, Starter Modules, Javascript Widgets, and SDKs makes adding member self-serve features to your site easy.

Self Serve Member Features Include:
- Protect and Access restricted pages and documents with role based security.
- updating Person profile
- updating communication preferences
- onboarding, purchasing and renewing a memberships (When paired with FuseBill)


# Group Main Resources

## People [/people]

People are the collection of person records in Wicket. The singular name of this resource is 'Person'

#### Additional Info

The Additional Information fields for Person records are accessible using the Person endpoints.

See the 'data_fields' attribute for the definition of how to fetch and post the additional info fields.

See the 'json_schemas_available' relationship on the Person entity to determine which additional info fields are available for the resource. These json schemas will be included by default when fetching a person and can be found within the 'included' section of the response.

**Important: data_fields Update Requirements**

When updating data_fields via PATCH endpoints, you must provide the complete structure for each field, including all nested properties. This is because data_fields are complex JSON structures validated against JSON schemas, and partial updates could break the schema validation or inadvertently remove required fields.

**Recommended Pattern: GET + PATCH**
1. First, GET the current resource to retrieve existing data_fields
2. Modify the complete field structure as needed
3. PATCH with the full data_fields structure

**Schema Identification**
You can identify the schema using either:
- `schema_slug` (recommended): Human-readable identifier like "custom-fields"
- `$schema`: UUID-based identifier like "urn:uuid:b3427c65-5383-438b-9c8e-409753b08b4e"

**Concurrent Update Prevention**
Use the `version` field in each data_field to prevent concurrent updates:
- Include the current `version` number from your GET request
- If the version has changed (another update occurred), you'll receive a `409 Conflict` error with code `record_conflict`
- This error indicates you should retry: GET the latest data, merge your changes, and PATCH again


+ Attributes (PersonAttributesWithUser)

### Fetch People [GET /people?page[number]={page_number}&page[size]={page_size}]&sort={sort}&filter[{attribute}_{search_matcher}]={filter_value}]

+ Parameters
    + page_number (number, `1`) -  Page number to retrieve from pagination (optional query string param)
    + page_size (number, `12`) - Number if items per page to retrieve from pagination (optional query string param)
    + sort (string, optional) - Attribute to sort by. Prepend with a minus sign(-) for descending sort. Example: `updated_at`
    + attribute (string, optional ) - The filter's target attribute. Example: `updated_at`
    + search_matcher (string, optional) -  Examples: `gt`. `eq`
    + filter_value (string, optional, `2021-01-01`)

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (PersonResponseResource)
        + included (PersonIncludes, required)
        + links (PeopleNavLinks)
        + meta (object, required)
            + page (PaginationMeta)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)



### Fetch a Single Person [GET /people/{person_id}]

+ Parameters
    + person_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Person's uuid

+ Response 200 (application/json)
    + Attributes
        + data (PersonResponseResource)
        + included (PersonIncludes, required)
            + (SchemaResponse)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)


### Fetch People by Email Address [GET /people?filter[emails_address_eq]={email_address}&{filter%5bemails_unique_eq%5d%3dtrue}]

+ Parameters
    + email_address (string) - Email address to match. Example: `help@wicket.io`
    + filter%5bemails_unique_eq%5d%3dtrue (string, optional) -  If present, only match a person's unique/primary email address.


+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (PersonResponseResource)
        + included (PersonIncludes, required)
        + links (PeopleNavLinks)
        + meta (object, required)
            + page (PaginationMeta)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Create Person [POST /people]

+ Request (application/json)
    + Attributes
        + data (PersonRequestResource)

+ Response 200 (application/json)
    + Attributes
        + data (PersonResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 409 (application/json)
    + Attributes (PersonEmailTakenResponse)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Update Person [PATCH /people/{person_id}]

**Note:** When updating `data_fields`, you must provide the complete structure for each field. See the Additional Info section above for details on the GET + PATCH pattern and concurrent update prevention. Use `schema_slug` instead of `$schema` for easier management.

+ Parameters
    + person_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Person's uuid

+ Request (application/json)
    + Attributes
        + data (PersonResourceIdentifier)
            + attributes (PersonAttributes)
                + data_fields (array, fixed-type)
                    + (PersonAttributesDataField)
                        + schema_slug: `custom-fields`
                        + version: 2
                        + value
                            + custom_field: `value`
                            + another_field: `another_value`
                + user (object) - Optional user details
                    + email: john.smith@acme.com (string) - New user only: email to mark as primary
                    + current_password: oldPassword (string) - Required when updating existing user credentials
                    + password: ex@mplePassword (string, required) - Password
                    + password_confirmation: ex@mplePassword (string) - Password Confirmation
                    + confirmed_at: `2018-03-26T00:00:00.000Z` (string) - New user, Admin Only: Bypass email confirmation
                    + skip_confirmation_notification: true (boolean) - New user only: Skip sending confirmation email when creating user

+ Response 200 (application/json)
    + Attributes
        + data (PersonResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Delete Person [DELETE /people/{person_id}]

+ Parameters
    + person_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Person's uuid

+ Request (application/json)

+ Response 204 (application/json)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 500 (application/json)
    + Attributes (Error500)

## Person - Resend password reset email [POST /people/{person_id}/reset-password]

+ Parameters
    + person_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Person's uuid

+ Request (application/json)
    + Body

            {}

+ Response 204

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

## Person - Resend account confirmation email [POST /people/{person_id}/resend-confirmation]

+ Parameters
    + person_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Person's uuid

+ Request (application/json)
    + Body

            {}

+ Response 204

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

## Connections [/connections]

In Wicket, connections represent a link between either a person and an organization or two individual people.

While in the API, these resources are named `Connections`, in the Wicket front-end they are referred to as `Relationships`.

+ Attributes
    + attributes (ConnectionAttributes)
    + relationships (ConnectionRelationships)

### Fetch Connections [GET /connections?{filter[connection_type_eq]}]

The `connection_type_eq` filter is used to return a specific type of connection. For backwards compatibility, not providing the parameter returns only connections of type Person to Organization. This is the same behaviour as providing the paramter 'person_to_organization'

+ Parameters
    + filter[connection_type_eq] (enum[string], optional)
        + Members
            + `person_to_organization`
            + `person_to_person`
            + `all`
        + Default: `person_to_organization`

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (ConnectionResponseResource)
        + included (ConnectionIncludes, required)
        + links (object)
        + meta (object, required)
            + page (PaginationMeta)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Fetch a Single Connection [GET /connections/{connection_id}]

+ Parameters
  + connection_id (string, `7f93c975-c1dc-46d1-9562-1df3b24b0c6d`) - Connection's uuid

+ Response 200 (application/json)
    + Attributes
        + data (ConnectionResponseResource)
        + included (ConnectionIncludes, required)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)


### Fetch Person's Connections [GET /people/{person_id}/connections?{filter[connection_type_eq]}]

The `connection_type_eq` filter is used to return a specific type of connection. For backwards compatibility, not providing the parameter returns only connections of type Person to Organization. This is the same behaviour as providing the paramter 'person_to_organization'

+ Parameters
  + person_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Person's uuid
  + filter[connection_type_eq] (enum[string], optional)
        + Members
            + `person_to_organization`
            + `person_to_person`
            + `all`
        + Default: `person_to_organization`
  

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (ConnectionResponseResource)
        + included (ConnectionIncludes, required)
        + links (object)
        + meta (object, required)
            + page (PaginationMeta)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Fetch Organization's Connections [GET /organizations/{organization_id}/connections]

+ Parameters
  + organization_id (string, `73cd91c1-76e4-4421-84df-acf1452cb8bc`) - Organization's uuid

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (ConnectionResponseResource)
        + included (ConnectionIncludes, required)
        + links (object)
        + meta (object, required)
            + page (PaginationMeta)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Create Connection [POST /connections]

+ Request (application/json)
    + Attributes
        + data (ConnectionRequestResource)

+ Response 200 (application/json)
    + Attributes
        + data (ConnectionResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Update Connection [PATCH /connections/{connection_id}]

+ Parameters
  + connection_id (string, `7f93c975-c1dc-46d1-9562-1df3b24b0c6d`) - Connection's uuid

+ Request (application/json)
    + Attributes
        + data (ConnectionRequestResource)

+ Response 200 (application/json)
    + Attributes
        + data (ConnectionResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Delete Connection [DELETE /connections/{connection_id}]

+ Parameters
  + connection_id (string, `7f93c975-c1dc-46d1-9562-1df3b24b0c6d`) - Connection's uuid

+ Request (application/json)

+ Response 204 (application/json)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 500 (application/json)
    + Attributes (Error500)

## Organizations [/organizations]

Organizations are one of the main Wicket entities. Organizations can be arranged in a parent/child hierarchy. Members in Wicket belong to an organization.

#### Additional Info

The Additional Information fields for Organization records are accessible using the Organization endpoints.

See the 'data_fields' attribute for the definition of how to fetch and post the additional info fields.

See the 'json_schemas_available' relationship on the Organization entity to determine which additional info fields are available for the resource. These json schemas will be included by default when fetching an organization and can be found within the 'included' section of the response.

**Important: data_fields Update Requirements**

When updating data_fields via PATCH endpoints, you must provide the complete structure for each field, including all nested properties. This is because data_fields are complex JSON structures validated against JSON schemas, and partial updates could break the schema validation or inadvertently remove required fields.

**Recommended Pattern: GET + PATCH**
1. First, GET the current resource to retrieve existing data_fields
2. Modify the complete field structure as needed
3. PATCH with the full data_fields structure

**Schema Identification**
You can identify the schema using either:
- `schema_slug` (recommended): Human-readable identifier like "custom-fields"
- `$schema`: UUID-based identifier like "urn:uuid:b3427c65-5383-438b-9c8e-409753b08b4e"

**Concurrent Update Prevention**
Use the `version` field in each data_field to prevent concurrent updates:
- Include the current `version` number from your GET request
- If the version has changed (another update occurred), you'll receive a `409 Conflict` error with code `record_conflict`
- This error indicates you should retry: GET the latest data, merge your changes, and PATCH again

+ Attributes (OrganizationAttributes)

### Fetch Organizations [GET /organizations?page[number]={page_number}&page[size]={page_size}&sort={sort}&filter[{attribute}_{search_matcher}]={filter_value}]

+ Parameters
    + page_number (number, `1`) -  Page number to retrieve from pagination (optional query string param)
    + page_size (number, `12`) - Number if items per page to retrieve from pagination (optional query string param)
    + sort (string, optional) - Attribute to sort by. Prepend with a minus sign(-) for descending sort. Example: `updated_at`
    + attribute (string, optional ) - The filter's target attribute. Example: `updated_at`
    + search_matcher (string, optional) -  Examples: `gt`, `eq`
    + filter_value (string, optional, `2021-01-01`)

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (OrganizationResponseResource)
        + links (object)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Fetch a Single Organization [GET /organizations/{organization_id}]

+ Parameters
    + organization_id (string, `73cd91c1-76e4-4421-84df-acf1452cb8bc`) - Organizations's uuid

+ Response 200 (application/json)
    + Attributes
        + data (OrganizationResponseResource)
        + included (OrganizationIncludes, required)
            + (SchemaResponse)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Create Organization [POST /organizations]

+ Request (application/json)
    + Attributes
        + data (OrganizationRequestResource)

+ Response 201 (application/json)
    + Attributes
        + data (OrganizationResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Update Organization [PATCH /organization/{organization_id}]

**Note:** When updating `data_fields`, you must provide the complete structure for each field. See the Additional Info section above for details on the GET + PATCH pattern and concurrent update prevention. Use `schema_slug` instead of `$schema` for easier management.

+ Parameters
    + organization_id (string, `73cd91c1-76e4-4421-84df-acf1452cb8bc`) - Organization's uuid

+ Request (application/json)
    + Attributes
        + data (OrganizationResourceIdentifier)
            + attributes (OrganizationAttributes)
                + data_fields (array, fixed-type)
                    + (OrganizationAttributesDataField)
                        + schema_slug: `org-custom-fields`
                        + version: 1
                        + value
                            + custom_field: `value`
                            + another_field: `another_value`

+ Response 200 (application/json)
    + Attributes
        + data (OrganizationResponseResource)
        + included (OrganizationIncludes, required)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Delete Organization [DELETE /organization/{organization_id}]

+ Parameters
    + organization_id (string, `73cd91c1-76e4-4421-84df-acf1452cb8bc`) - Organization's uuid

+ Request (application/json)

+ Response 204 (application/json)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 500 (application/json)
    + Attributes (Error500)

## Groups [/groups]

Groups are one of the main Wicket entities. People in Wicket can belong to a group as group members.

+ Attributes
    + group attributes (GroupAttributes)
    + group relationships (GroupRelationships)
    + group_member attributes (GroupMemberAttributes)
    + group_member relationships (GroupMemberRelationships)

### Fetch Groups [GET /groups?page[number]={page_number}&page[size]={page_size}&sort={sort}&filter[{attribute}_{search_matcher}]={filter_value}]

+ Parameters
    + page_number (number, `1`) -  Page number to retrieve from pagination (optional query string param)
    + page_size (number, `12`) - Number of items per page to retrieve from pagination (optional query string param)
    + sort (string, optional) - Attribute to sort by. Prepend with a minus sign(-) for descending sort. Example: `updated_at`
    + attribute (string, optional ) - The filter's target attribute. Example: `updated_at`
    + search_matcher (string, optional) -  Examples: `gt`, `eq`
    + filter_value (string, optional, `2021-01-01`)

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (GroupResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Fetch a Single Group [GET /groups/{group_id}]

+ Parameters
    + group_id (string, `2bfa2f56-6b9a-4fe4-bd11-e2f9919b9933`) - Group's uuid

+ Response 200 (application/json)
    + Attributes
        + data (GroupResponseResource)
        + included (GroupIncludes, required)
            + (SchemaResponse)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Create Group [POST /groups]

+ Request (application/json)
    + Attributes
        + data (GroupRequestResource)

+ Response 201 (application/json)
    + Attributes
        + data (GroupResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Update Group [PATCH /groups/{group_id}]

+ Parameters
    + group_id (string, `2bfa2f56-6b9a-4fe4-bd11-e2f9919b9933`) - Group's uuid

+ Request (application/json)
    + Attributes
        + data (GroupResourceIdentifier)
            + attributes (GroupCreateOrUpdateAttributes)
            + relationships (GroupCreateOrUpdateRelationships)

+ Response 200 (application/json)
    + Attributes
        + data (GroupResponseResource)
        + included (GroupIncludes, required)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Delete Group [DELETE /groups/{group_id}]

+ Parameters
    + group_id (string, `2bfa2f56-6b9a-4fe4-bd11-e2f9919b9933`) - Group's uuid

+ Request (application/json)

+ Response 204 (application/json)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 500 (application/json)
    + Attributes (Error500)

### Fetch Group Members [GET /group_members]

+ Parameters
    + page_number (number, `1`) -  Page number to retrieve from pagination (optional query string param)
    + page_size (number, `12`) - Number of items per page to retrieve from pagination (optional query string param)
    + sort (string, optional) - Attribute to sort by. Prepend with a minus sign(-) for descending sort. Example: `updated_at`
    + attribute (string, optional ) - The filter's target attribute. Example: `updated_at`
    + search_matcher (string, optional) -  Examples: `gt`, `eq`
    + filter_value (string, optional, `2021-01-01`)

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (GroupMemberResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Fetch a Group's Members [GET /groups/{group_id}/people]

+ Parameters
    + group_id (string, `2bfa2f56-6b9a-4fe4-bd11-e2f9919b9933`) - Group entity's uuid
    + page_number (number, `1`) -  Page number to retrieve from pagination (optional query string param)
    + page_size (number, `12`) - Number of items per page to retrieve from pagination (optional query string param)
    + sort (string, optional) - Attribute to sort by. Prepend with a minus sign(-) for descending sort. Example: `updated_at`
    + attribute (string, optional ) - The filter's target attribute. Example: `updated_at`
    + search_matcher (string, optional) -  Examples: `gt`, `eq`
    + filter_value (string, optional, `2021-01-01`)

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (GroupMemberResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Fetch a Single Group Member [GET /group_members/{group_member_id}]

+ Parameters
    + group_member_id (string, `8cb57ad8-895f-43f7-a3e2-013808859ea1`) - Group Member entity's uuid

+ Response 200 (application/json)
    + Attributes
        + data (GroupMemberResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Create Group Member  [POST /group_members]

+ Request (application/json)
    + Attributes
        + data (GroupMemberRequestResource)

+ Response 201 (application/json)
    + Attributes
        + data (GroupMemberResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Update Group Member [PATCH /group_members/{group_member_id}]

+ Parameters
    + group_member_id (string, `8cb57ad8-895f-43f7-a3e2-013808859ea1`) - Group Member entity's uuid

+ Request (application/json)
    + Attributes
        + data (object)
            + id: `8cb57ad8-895f-43f7-a3e2-013808859ea1` (string)
            + type: `group_members` (string)
            + attributes (GroupMemberCreateOrUpdateAttributes)
            + relationships (GroupMemberRelationships)

+ Response 200 (application/json)
    + Attributes
        + data (GroupMemberResponseResource)
        + included (GroupMemberIncludes, required)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)


### Delete Group Member [DELETE /group_members/{group_member_id}]

+ Parameters
    + group_member_id (string, `8cb57ad8-895f-43f7-a3e2-013808859ea1`) - Group Member entity's uuid

+ Request (application/json)

+ Response 204 (application/json)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 500 (application/json)
    + Attributes (Error500)

## Touchpoints [/touchpoints]

In Wicket, touchpoints represent interactions that members have with Wicket or with the suite of software for which Wicket has integrations.

A touchpoint is unique to a service (i.e. Wicket integration) and it represents a kind of action that the user peformed in that service (i.e. logged in, purchased an item, registered for an event, etc...)

The current version of the API does not support updates or deletion of touchpoints. This is by design as touchpoints are meant to be a track record of user actions. If you need to ammend a real world action you can log a subsequent touchpoint that updates or negates the previous action.

+ Attributes (TouchpointAttributes)

### Fetch Touchpoints [GET /touchpoints?page[number]={page_number}&page[size]={page_size}]

+ Parameters
  + page_number (number, `1`) -  Page number to retrieve from pagination (optional query string param)
  + page_size (number, `12`) - Number if items per page to retrieve from pagination (optional query string param)

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (TouchpointResponseResource)
        + included (TouchpointIncludes, required)
        + links (TouchpointsNavLinks)
        + meta (object, required)
            + page (PaginationMeta)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Fetch a Single Touchpoint [GET /touchpoints/{touchpoint_id}]

+ Parameters
  + touchpoint_id (string, `ea03ad13-ad3a-4058-88df-c365ed68c5fd`) - Touchpoint's uuid

+ Response 200 (application/json)
    + Attributes
        + data (TouchpointResponseResource)
        + included (TouchpointIncludes, required)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Fetch Person's Touchpoints [GET /people/{person_id}/touchpoints?page[number]={page_number}&page[size]={page_size}]

+ Parameters
  + person_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Person's uuid
  + page_number (number, `1`) -  Page number to retrieve from pagination (optional query string param)
  + page_size (number, `12`) - Number if items per page to retrieve from pagination (optional query string param)

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (TouchpointResponseResource)
        + included (TouchpointIncludes, required)
        + links (TouchpointsNavLinks)
        + meta (object, required)
            + page (PaginationMeta)
            + stats (TouchpointStats)
            + facets (TouchpointFacets)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Create Touchpoint [POST /touchpoints]

+ Request (application/json)
    + Attributes
        + data (TouchpointRequestResource)

+ Response 200 (application/json)
    + Attributes
        + data (TouchpointResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

## Person Memberships [/person_memberships]

The person membership resource in Wicket is used to represent both current and past standalone membership for a Person to a specific tier.

A membership is considered active when the current UTC time falls between the starts_at / ends_at on the resource. If one or both of these timestimps are null the membership will be open-ended or active indefinitely.

When a `organization_membership` is relationship specified the membership is considered an organization membership assignment.

In addition to the standard attribute based filters the following are also available on the collection endpoints:


Filter | Value | Notes
-----: | :------ | :----
filter[active_at] | 2020-12-01T00:00:00.000Z | To filter currently active memberships `now` can be used instead of a timestamp.


+ Attributes (PersonMembershipResponseResource)

### Fetch all Person Memberships [GET /person_memberships?page[number]={page_number}&page[size]={page_size}&sort={sort}&filter[active_at]={active_date_value}&with_deleted={with_deleted}]

+ Parameters
    + page_number (number, `1`) -  Page number to retrieve from pagination (optional query string param)
    + page_size (number, `12`) - Number if items per page to retrieve from pagination (optional query string param)
    + active_date_value (string, optional) -  Only fetch active memberships at given date. Examples: `now`, `2020-12-01T00:00:00.000Z`
    + with_deleted (string, optional) - Include deleted records in the response. Admin only.
+ Response 200 (application/json)
    + Attributes
        + data (array[PersonMembershipResponseResource])
        + links (object)

### Fetch Person's memberships [GET /people/{person_id}/membership_entries?page[number]={page_number}&page[size]={page_size}&sort={sort}&filter[active_at]={active_date_value}&with_deleted={with_deleted}]

+ Parameters
    + person_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Person's uuid
    + page_number (number, `1`) -  Page number to retrieve from pagination (optional query string param)
    + page_size (number, `12`) - Number if items per page to retrieve from pagination (optional query string param)
    + active_date_value (string, optional) -  Only fetch active memberships at given date. Examples: `now`, `2020-12-01T00:00:00.000Z`
    + with_deleted (string, optional) - Include deleted records in the response. Admin only.
+ Response 200 (application/json)
    + Attributes
        + data (array[PersonMembershipResponseResource])
        + links (object)

### Fetch a Single Person Membership [GET /person_memberships/{id}]

+ Parameters
    + id (string, `224a4ac2-c528-4f36-8ed6-f83195cd79a5`)

+ Response 200 (application/json)
    + Attributes
        + data (PersonMembershipResponseResource)

### Create a Person Membership [POST]

+ Request Simple membership (application/json)
    + Attributes
        + data
            + type: `person_memberships`
            + attributes
                + starts_at: `2020-01-01T00:00:00.000Z`
                + ends_at: `2020-12-31T00:00:00.000Z`
            + relationships
                + membership
                    + data (required, MembershipResourceIdentifier)
                + person
                    + data (required, PersonResourceIdentifier)

+ Response 200 (application/json)
    + Attributes
        + data (PersonMembershipResourceIdentifier)
            + attributes (PersonMembershipAttributes)
            + relationships (PersonMembershipRelationships)
                + organization_membership
                    + data: null (object, nullable, required)
                + fusebill_subscription
                    + data: null (object, nullable, required)

+ Request Create Organization Membership assignment (application/json)
    + Attributes
        + data
            + type: `person_memberships`
            + attributes
                + starts_at: `2020-01-01T00:00:00.000Z`
                + ends_at: `2020-12-31T00:00:00.000Z`
            + relationships
                + organization_membership
                    + data (required, OrganizationMembershipResourceIdentifier)
                + person
                    + data (required, PersonResourceIdentifier)

+ Response 200 (application/json)
    + Attributes
        + data (PersonMembershipResourceIdentifier)
            + attributes (PersonMembershipAttributes)
            + relationships (PersonMembershipRelationships)
                - fusebill_subscription
                    - data: null (object, nullable, required)

+ Response 400 (application/json)
    + Attributes (Error400)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Update Person Membership [PATCH /person_memberships/{id}]

+ Parameters
  + id (string, `224a4ac2-c528-4f36-8ed6-f83195cd79a5`)

+ Request (application/json)
    + Attributes
        + data
            + attributes
                + starts_at: `2020-01-01T00:00:00.000Z`
                + ends_at: `2020-12-31T00:00:00.000Z`
            + relationships
                + membership
                    + data (optional, MembershipResourceIdentifier)
                + person
                    + data (optional, PersonResourceIdentifier)

+ Response 200 (application/json)
    + Attributes
        + data (PersonMembershipResourceIdentifier)
            + attributes (PersonMembershipAttributes)
            + relationships (PersonMembershipRelationships)
                - fusebill_subscription
                    - data: null (object, nullable, required)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Delete Person Membership [DELETE /person_memberships/{id}]

+ Parameters
  + id (string, `224a4ac2-c528-4f36-8ed6-f83195cd79a5`)

+ Request (application/json)

+ Response 204 (application/json)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 500 (application/json)
    + Attributes (Error500)

## Organization Memberships [/organization_memberships]

The organization membership resource in Wicket is used to represent both current and past standalone membership for an Organization to a specific tier.

A membership is considered active when the current UTC time falls between the starts_at / ends_at on the resource. If one or both of these timestimps are null the membership will be open-ended or active indefinitely.

An organization membership can have people assigned to it, the maximum number of assigments is determined by the `max_assignments` attribute. When specified the number of **active** assigments at one time must not exceed this count.

These assigments can be created via the /person_memberships endpoint by specificing the `organization_membership` relationship. See the "Create a Person Membership" examples for more details.

In addition to the standard attribute based filters the following are also available on the collection endpoints:


Filter | Example | Notes
-----: | :------ | :----
filter[active_at] | 2020-12-01T00:00:00.000Z | To filter currently active memberships `now` can be used instead of a timestamp.


+ Attributes (OrganizationMembershipResponseResource)

### Fetch all Organization Memberships [GET /organization_memberships?page[number]={page_number}&page[size]={page_size}&sort={sort}&filter[active_at]={active_date_value}&with_deleted={with_deleted}]

+ Parameters
    + page_number (number, `1`) -  Page number to retrieve from pagination (optional query string param)
    + page_size (number, `12`) - Number if items per page to retrieve from pagination (optional query string param)
    + active_date_value (string, optional) -  Only fetch active memberships at given date. Examples: `now`, `2020-12-01T00:00:00.000Z`
    + with_deleted (string, optional) - Include deleted records in the response. Admin only. 
+ Response 200 (application/json)
    + Attributes
        + data (array[OrganizationMembershipResponseResource])
        + links (object)

### Fetch Organization's memberships [GET /organizations/{organization_id}/membership_entries?page[number]={page_number}&page[size]={page_size}&sort={sort}&filter[active_at]={active_date_value}&with_deleted={with_deleted}]

+ Parameters
    + organization_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Organization's uuid
    + page_number (number, `1`) -  Page number to retrieve from pagination (optional query string param)
    + page_size (number, `12`) - Number if items per page to retrieve from pagination (optional query string param)
    + active_date_value (string, optional) -  Only fetch active memberships at given date. Examples: `now`, `2020-12-01T00:00:00.000Z`
    + with_deleted (string, optional) - Include deleted records in the response. Admin only.
+ Response 200 (application/json)
    + Attributes
        + data (array[OrganizationMembershipResponseResource])
        + links (object)

### Fetch a Single Organization Membership [GET /organization_memberships/{id}]

+ Parameters
    + id (string, `c330d18e-0aa1-404f-9a91-8540817fecf7`)

+ Response 200 (application/json)
    + Attributes
        + data (OrganizationMembershipResponseResource)

### Fetch assigments for an Organization Membership [GET /organization_memberships/{id}/person_memberships?page[number]={page_number}&page[size]={page_size}&sort={sort}]

+ Parameters
    + id (string, `c330d18e-0aa1-404f-9a91-8540817fecf7`) - Organization membership id
    + page_number (number, `1`) -  Page number to retrieve from pagination (optional query string param)
    + page_size (number, `12`) - Number if items per page to retrieve from pagination (optional query string param)

+ Response 200 (application/json)
    + Attributes
        + data (array[PersonMembershipResponseResource])
        + links (object)

### Create Organization Membership [POST]

When creating an organization membership, the membership tier relationship must have a type of `organization`. The `max_assignments` property will be inherited from the membership tier and cannot be modified at this time.

+ Request Simple membership (application/json)
    + Attributes
        + data
            + type: `organization_memberships`
            + attributes
                + starts_at: `2020-01-01T00:00:00.000Z`
                + ends_at: `2020-12-31T00:00:00.000Z`
            + relationships
                + membership
                    + data (required, MembershipResourceIdentifier)
                + organization
                    + data (required, OrganizationResourceIdentifier)
                + owner
                    + data (required, PersonResourceIdentifier)

+ Response 200 (application/json)
    + Attributes
        + data (OrganizationMembershipResourceIdentifier)
            + attributes (OrganizationMembershipAttributes)
            + relationships (OrganizationMembershipRelationships)
                + fusebill_subscription
                    + data: null (object, nullable, required)

+ Response 400 (application/json)
    + Attributes (Error400)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Update Organization Membership [PATCH /organization_memberships/{id}]

+ Parameters
  + id (string, `c330d18e-0aa1-404f-9a91-8540817fecf7`)

+ Request (application/json)
    + Attributes
        + data
            + attributes
                + starts_at: `2020-01-01T00:00:00.000Z`
                + ends_at: `2020-12-31T00:00:00.000Z`
            + relationships
                + organization
                    + data (optional, OrganizationResourceIdentifier)
                + owner
                    + data (optional, PersonResourceIdentifier)

+ Response 200 (application/json)
    + Attributes
        + data (OrganizationMembershipResourceIdentifier)
            + attributes (OrganizationMembershipAttributes)
            + relationships (OrganizationMembershipRelationships)
                - fusebill_subscription
                    - data: null (object, nullable, required)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Delete Organization Membership [DELETE /organization_memberships/{id}]

+ Parameters
  + id (string, `c330d18e-0aa1-404f-9a91-8540817fecf7`)

+ Request (application/json)

+ Response 204 (application/json)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 500 (application/json)
    + Attributes (Error500)

# Group Secondary Resources

## Addresses [/addresses]

Addresses in Wicket are associated with either Person or Organization primary resources.

Addresses can be fetched as a list or created from the primary resource end point or fetched, updated and destroyed from the secondary resource end point

+ Attributes (AddressAttributes)

### Fetch Person's Addresses [GET /people/{person_id}/addresses]

+ Parameters
  + person_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Person's uuid

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (PersonAddressResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Fetch Organization's Addresses [GET /organizations/{organization_id}/addresses]

+ Parameters
  + organization_id (string, `73cd91c1-76e4-4421-84df-acf1452cb8bc`) - Organization's uuid

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (OrganizationAddressResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Fetch a Single Address [GET /addresses/{address_id}]

This example response will show a person's address response. However, the 'addressable' field in relationships could be of resource type of 'organizations' too.

+ Parameters
  + address_id (string, `df1110d1-df3d-4a9c-8355-b82e25832a0e`) - Address's uuid

+ Response 200 (application/json)
    + Attributes
        + data (PersonAddressResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Create Person's Address [POST /people/{person_id}/addresses]

+ Parameters
  + person_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Person's uuid

+ Request (application/json)
    + Attributes
        + data (AddressRequestResource)

+ Response 200 (application/json)
    + Attributes
        + data (PersonAddressResponseResource)
        + included (AddressIncludes)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Create Organization's Address [POST /organizations/{organization_id}/addresses]

+ Parameters
  + organization_id (string, `73cd91c1-76e4-4421-84df-acf1452cb8bc`) - Organization's uuid

+ Request (application/json)
    + Attributes
        + data (AddressRequestResource)

+ Response 200 (application/json)
    + Attributes
        + data (OrganizationAddressResponseResource)
        + included (array)
            + (OrganizationResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Update Address [PATCH /addresses/{address_id}]

This example response will show a person's address response. However, the 'addressable' field in relationships could be of resource type of 'organizations' too.

+ Parameters
  + address_id (string, `df1110d1-df3d-4a9c-8355-b82e25832a0e`) - Address's uuid

+ Request (application/json)
    + Attributes
        + data (AddressRequestResource)

+ Response 200 (application/json)
    + Attributes
        + data (PersonAddressResponseResource)
        + included (AddressIncludes)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Delete Address [DELETE /addresses/{address_id}]

+ Parameters
  + address_id (string, `df1110d1-df3d-4a9c-8355-b82e25832a0e`) - Address's uuid

+ Request (application/json)

+ Response 204 (application/json)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 500 (application/json)
    + Attributes (Error500)


## Phones [/phones]

Phones in Wicket are associated with either Person or Organization primary resources.

Phones can be fetched as a list or created from the primary resource end point or fetched, updated and destroyed from the secondary resource end point

+ Attributes (PhoneAttributes)

### Fetch Person's Phones [GET /people/{person_id}/phones]

+ Parameters
  + person_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Person's uuid

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (PersonPhoneResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Fetch Organization's Phones [GET /organizations/{organization_id}/phones]

+ Parameters
  + organization_id (string, `73cd91c1-76e4-4421-84df-acf1452cb8bc`) - Organization's uuid

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (OrganizationPhoneResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Fetch a Single Phone [GET /phones/{phone_id}]

This example response will show a person's phone response. However, the 'phoneable' field in relationships could be of resource type of 'organizations' too.

+ Parameters
  + phone_id (string, `4a1e4827-00ac-4576-b383-39c492ccf029`) - Phone's uuid

+ Response 200 (application/json)
    + Attributes
        + data (PersonPhoneResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Create Person's Phone [POST /people/{person_id}/phones]

+ Parameters
  + person_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Person's uuid

+ Request (application/json)
    + Attributes
        + data (PhoneRequestResource)

+ Response 200 (application/json)
    + Attributes
        + data (PersonPhoneResponseResource)
        + included (PhoneIncludes)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Create Organization's Phone [POST /organizations/{organization_id}/phones]

+ Parameters
  + organization_id (string, `73cd91c1-76e4-4421-84df-acf1452cb8bc`) - Organization's uuid

+ Request (application/json)
    + Attributes
        + data (PhoneRequestResource)

+ Response 200 (application/json)
    + Attributes
        + data (OrganizationPhoneResponseResource)
        + included (array)
            + (OrganizationResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Update Phone [PATCH /phones/{phone_id}]

This example response will show a person's phone response. However, the 'phoneable' field in relationships could be of resource type of 'organizations' too.

+ Parameters
  + phone_id (string, `4a1e4827-00ac-4576-b383-39c492ccf029`) - Phone's uuid

+ Request (application/json)
    + Attributes
        + data (PhoneRequestResource)

+ Response 200 (application/json)
    + Attributes
        + data (PersonPhoneResponseResource)
        + included (PhoneIncludes)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Delete Phone [DELETE /phones/{phone_id}]

+ Parameters
  + phone_id (string, `4a1e4827-00ac-4576-b383-39c492ccf029`) - Phone's uuid

+ Request (application/json)

+ Response 204 (application/json)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 500 (application/json)
    + Attributes (Error500)


## Emails [/emails]

Emails in Wicket are associated with either Person or Organization primary resources.

Emails can be fetched as a list or created from the primary resource end point or fetched, updated and destroyed from the secondary resource end point

+ Attributes (EmailAttributes)

### Fetch Person's Emails [GET /people/{person_id}/emails]

+ Parameters
  + person_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Person's uuid

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (PersonEmailResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Fetch Organization's Emails [GET /organizations/{organization_id}/emails]

+ Parameters
  + organization_id (string, `73cd91c1-76e4-4421-84df-acf1452cb8bc`) - Organization's uuid

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (OrganizationEmailResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Fetch a Single Email [GET /emails/{email_id}]

This example response will show a person's email response. However, the 'emailable' field in relationships could be of resource type of 'organizations' too.

+ Parameters
  + email_id (string, `4beb895d-3703-4400-876b-a1a985aebf57`) - Email's uuid

+ Response 200 (application/json)
    + Attributes
        + data (PersonEmailResponseResource)
        + included (EmailIncludes)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Create Person's Email [POST /people/{person_id}/emails]

+ Parameters
  + person_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Person's uuid

+ Request (application/json)
    + Attributes
        + data (EmailRequestResource)

+ Response 200 (application/json)
    + Attributes
        + data (PersonEmailResponseResource)
        + included (EmailIncludes)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Create Organization's Email [POST /organizations/{organization_id}/emails]

+ Parameters
  + organization_id (string, `73cd91c1-76e4-4421-84df-acf1452cb8bc`) - Organization's uuid

+ Request (application/json)
    + Attributes
        + data (EmailRequestResource)

+ Response 200 (application/json)
    + Attributes
        + data (OrganizationEmailResponseResource)
        + included (array)
            + (OrganizationResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Update Email [PATCH /emails/{email_id}]

This example response will show a person's email response. However, the 'emailable' field in relationships could be of resource type of 'organizations' too.

+ Parameters
  + email_id (string, `4beb895d-3703-4400-876b-a1a985aebf57`) - Email's uuid

+ Request (application/json)
    + Attributes
        + data (EmailRequestResource)

+ Response 200 (application/json)
    + Attributes
        + data (PersonEmailResponseResource)
        + included (EmailIncludes)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Delete Email [DELETE /emails/{email_id}]

+ Parameters
  + email_id (string, `4beb895d-3703-4400-876b-a1a985aebf57`) - Email's uuid

+ Request (application/json)

+ Response 204 (application/json)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 500 (application/json)
    + Attributes (Error500)


# Group User Provisioning


In Wicket, person records may exist without credentials or a user role attached—often in cases where a touchpoint was recorded via an integration. This can lead to conflicts when users try to sign up on your member portal using the same email address.

The following flow outlines the recommended handling of this scenario. The [Create Person](#reference/main-resources/people/create-person) endpoint will return a 409 error if the email address is already in use. To proceed, make a follow-up API call to the [Update Person](#reference/main-resources/people/update-person) endpoint of the existing person record. You may choose to send only user-specific attributes or include additional person attributes to overwrite them.

This process ensures that:

- The person has a user role attached.
- Confirmation emails are sent to the user.
- If claiming a unique email, it will be changed to primary.

[![claim process image](https://static-assets.wicket.io/api-docs/diagrams/local_user_provision_flow.svg)](https://static-assets.wicket.io/api-docs/diagrams/local_user_provision_flow.svg)

**Note:** If your implementation uses delegated Single Sign-On (SSO), refer to the [User Identities](#reference/user-provisioning/user-identities) section below for additional details on managing and linking identities.

## User Identities [/user_identities]

In Wicket, user identities are used for tracking the ids of users in external SSO systems such as B2C or Auth0. The provision endpoint is also provided to help with creating new users or attaching to existing person records.

Provisioning user identities follows this process. Ensure all additional preconditions are verified before calling this API endpoint.

For any further changes to a person’s record, a subsequent API call can be made to /people/:uuid using the returned person relationship from the user identity record.

[![process image](https://static-assets.wicket.io/api-docs/diagrams/user_identity_provision_flow.svg)](https://static-assets.wicket.io/api-docs/diagrams/user_identity_provision_flow.svg)

+ Attributes (UserIdentityResponseAttributes)

### Fetch User Identities [GET /user_identities?page[number]={page_number}&page[size]={page_size}]

+ Parameters
  + page_number (number, `1`) -  Page number to retrieve from pagination (optional query string param)
  + page_size (number, `12`) - Number if items per page to retrieve from pagination (optional query string param)
  + include: (string) - CSV list of relationships to include. Allowed: user,user_identity_provider,person

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (UserIdentityResponseResource)
        + included (UserIdentityIncludes, required)
        + links (object)
        + meta (object, required)
            + page (PaginationMeta)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Fetch Person's user identities [GET /people/{person_id}/user_identities?page[number]={page_number}&page[size]={page_size}]

+ Parameters
  + person_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Person's uuid
  + page_number (number, `1`) -  Page number to retrieve from pagination (optional query string param)
  + page_size (number, `12`) - Number if items per page to retrieve from pagination (optional query string param)
  + include: (string) - CSV list of relationships to include. Allowed: user,user_identity_provider,person

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (UserIdentityResponseResource)
        + included (UserIdentityIncludes, required)
        + links (object)
        + meta (object, required)
            + page (PaginationMeta)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Provision a user identity by email and external ID [POST /user_identities/provision]

+ Parameters
  + include: (string) - CSV list of relationships to include. Allowed: user,user_identity_provider,person

+ Request (application/json)
    + Attributes
        + data (UserIdentityRequestResource)

+ Response 200 (application/json)
    + Attributes
        + data (UserIdentityResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 409 (application/json)
    + Attributes (Error409)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

# Group Supplemental Resources

## Roles [/roles]

Roles in Wicket are assigned to Person records. Roles can be scoped at the Organization level or they can be "System" roles that are not scoped to a specific Organization. Through the API you may work with Roles as an entity (Add, Edit, List, Delete roles in Wicket), or you may work with the Roles in the context of a Person (Assign, Revoke, List roles for a given person).

A system role in Wicket is a role with a null "resource" relationship. Currently creating new roles via the API is limited to System roles. You may still query, update and delete Organization Roles. For organizational roles, the "resource" relationship will be set to a organization resource identifier.

eg. { "type": "organizations", "id": "73cd91c1-76e4-4421-84df-acf1452cb8bc" }

When checking for organization roles be sure to compare both the type and id, as new types of resources may be added in the future.


+ Attributes (RoleAttributes)

### Fetch Roles [GET /roles]

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (RoleResponseResource)
            + (RoleResponseResourceWithOrg)
        
+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)


### Fetch a Single Role [GET /roles/{id}]

+ Parameters
    + id (string, `4a361828-5ce4-4e2d-b271-d7a81af8fff7`) - Role's uuid

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (RoleResponseResource)
        
+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Create Role [POST /roles]

+ Request (application/json)
    + Attributes
        + data (RoleRequestResource)

+ Response 200 (application/json)
    + Attributes
        + data (RoleResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)


### Update Role [PATCH /roles/{id}]

+ Parameters
    + id (string, `4a361828-5ce4-4e2d-b271-d7a81af8fff7`) - Role's uuid

+ Request (application/json)
    + Attributes
        + data (RoleResourceIdentifier)
            + attributes (RoleAttributes)

+ Response 200 (application/json)
    + Attributes
        + data (RoleResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)


### Delete Role [DELETE /roles/{id}]

+ Parameters
    + id (string, `4a361828-5ce4-4e2d-b271-d7a81af8fff7`) - Role's uuid

+ Request (application/json)

+ Response 204 (application/json)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 500 (application/json)
    + Attributes (Error500)


### Fetch Person's Roles [GET /people/{id}/roles]

+ Parameters
    + id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Person's uuid

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (RoleResponseResource)
            + (RoleResponseResourceWithOrg)
        
+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)


### Assign Role to Person [POST /people/{id}/relationships/roles]

+ Parameters
    + id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Person's uuid

+ Request (application/json)
    + Attributes
        + data (array)
            + (RoleResourceIdentifier)

+ Response 200 (application/json)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)


### Revoke a Person's Role [DELETE /people/{id}/relationships/roles]

+ Parameters
    + id (string, `4a361828-5ce4-4e2d-b271-d7a81af8fff7`) - Person's uuid

+ Request (application/json)
    + Attributes
        + data (array)
            + (RoleResourceIdentifier)

+ Response 204 (application/json)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 500 (application/json)
    + Attributes (Error500)

## ResourceTags [/resource_tags]

In Wicket the ResourceTags resource represents all the tags linked to other higher level resource types in Wicket such as Organizations, People and Connections.

ResourceTags are generated dynamically based on tags created within Wicket, the attributes included will change depending on the tag contexts available for the
resource type. The default tag context called `tags` is included for all types.

Tags contexts are used by resources in Wicket to track lists of tags in different categories, for example the OrderType resource has a context called `restricted_orderable_tags`

+ Attributes (ResourceTagsAttributes)

### Fetch all Resource tags [GET /resource_tags]

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (ResourceTagsResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)

### Fetch resource tags [GET /resource_tags/{resource_type}]

+ Parameters
    + resource_type (string, required) - Type of resource to fetch tags for.

+ Response 200 (application/json)
    + Attributes
        + data (ResourceTagsResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 500 (application/json)
    + Attributes (Error500)

## Membership Tiers [/memberships]

Membership Tiers are the collection of membership tier records in Wicket. The singluar name of this resource is 'Membership Tier'

+ Attributes (MembershipAttributes)

### Fetch Membership Tiers [GET /memberships?page[number]={page_number}&page[size]={page_size}&sort={sort}]

+ Parameters
    + page_number (number, `1`) -  Page number to retrieve from pagination (optional query string param)
    + page_size (number, `12`) - Number if items per page to retrieve from pagination (optional query string param)
    + sort (string, `name_en`) - Specifies the sort param

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (MembershipResponseResource)
        + included (MembershipIncludes, required)
        + links (object)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)


### Fetch a Single Membership Tier [GET /memberships/{membership_id}]

+ Parameters
    + membership_id (string, `f8ddce1e-6938-4216-9ce9-f24a6b7cd677`) - Membership's uuid

+ Response 200 (application/json)
    + Attributes
        + data (MembershipResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)


### Create Membership Tier [POST /memberships]

+ Request (application/json)
    + Attributes
        + data (MembershipRequestResource)

+ Response 201 (application/json)
    + Attributes
        + data (MembershipResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)


### Update Membership Tier [PATCH /memberships/{membership_id}]

+ Parameters
    + membership_id (string, `f8ddce1e-6938-4216-9ce9-f24a6b7cd677`) - Membership's uuid

+ Request (application/json)
    + Attributes
        + data (MembershipResourceIdentifier)
            + attributes (MembershipAttributes)

+ Response 200 (application/json)
    + Attributes
        + data (MembershipResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)


### Delete Membership Tier [DELETE /membership/{membership_id}]

+ Parameters
    + membership_id (string, `f8ddce1e-6938-4216-9ce9-f24a6b7cd677`) - Membership's uuid

+ Request (application/json)

+ Response 204 (application/json)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 500 (application/json)
    + Attributes (Error500)

# Data Structures

## PersonResourceIdentifier (object)
+ id: `8f24a446-acac-40bb-8ff3-e6abc051c362` (required) - Person's uuid
+ type: `people` (required) - Resource Type

## PersonAttributes (object)
+ given_name: `John` - Given name of the person (first)
+ family_name: `Smith` - Family name of the person (last)
+ additional_name: `J.S.` - Used for initials in Wicket admin
+ alternate_name: `Jon` - Alternate name
+ full_name: `John Smith` - Full name (first and last)
+ identifying_number: `12345` (number) - Unique number that can be used to identify the person record
+ slug: `jon` - Slug. Currently populates with alternate name in lowercase
+ gender: `man` - Gender
+ honorific_prefix: `Mr.`  - Used for salutation in Wicket admin
+ honorific_suffix: `Ph.D` - Honorific suffix (not used in Wicket admin)
+ preferred_pronoun: `he-him-his` - Preferred pronoun
+ job_title: `Front End Developer` - Job Title
+ birth_date: `1900-01-01` - Birth date
+ language: en, fr (enum) - Primary language. If a value other than "en" or "fr" is provided, the person will not be considered valid.
+ languages_spoken: `english` (array) - Languages spoken (not used in Wicket admin)
+ languages_written: `english` (array) - Languages written (not used in Wicket admin)
+ data_fields (array[PersonAttributesDataField]) - Custom fields stored on the person record, validated against JSON Schema

## PersonAttributesWithUser (PersonAttributes)

+ user (object)
    + email: `jsmith@email.ca` (string) - Primary email address of the user
    + username: `jsmith` (string) - Legacy username field, no longer used.
    + reset_password_sent_at: `2018-03-26T00:00:00.000Z` (string) - Read Only: Timestamp for latest password reset sent to user.
    + confirmation_sent_at: `2018-03-26T00:00:00.000Z` (string) - Read Only: Timestamp for latest confirmation email sent to user.
    + confirmed_at: `2018-03-26T00:00:00.000Z` (string) - Timestamp when the user confirmed their account. Can only be set by admins when creating user record, otherwise set by the system when the Person confirms their email

## PersonResponseAttributes (PersonAttributes)
+ uuid: `8f24a446-acac-40bb-8ff3-e6abc051c362`
+ created_at: `2017-07-27T22:21:26.824Z` - ISO 8601 of creation time
+ updated_at: `2017-07-27T22:47:45.129Z` - ISO 8601 of last modification
+ membership_number: `1234` - Membership number
+ membership_began_on: `1900-01-01` - Date of start of membership
+ tags (array)
+ data (object)
    + communications (object)
        + email: true (boolean) - Whether the user whiches to receive notifications
        + sublists (object)
            + one: true (boolean) - Sublist one preference
            + two: true (boolean) - Sublist two preference
            + three: true (boolean) - Sublist three preference
            + four: true (boolean) - Sublist four preference
            + five: true (boolean) - Sublist five preference
        + sync_services: true (boolean) - Whether the user data will be synced to other services (i.e. mailchimp)
        + journal_physical_copy: true (boolean) - Internal use
+ role_names: `user,member` (array)
+ user (object)
    + email: `jsmith@email.ca` (string)
    + username: `jsmith` (string)
    + reset_password_sent_at: `2018-03-26T00:00:00.000Z` (string)
    + confirmation_sent_at: `2018-03-26T00:00:00.000Z` (string)
    + confirmed_at: `2018-03-26T00:00:00.000Z` (string)

## PersonAttributesDataField

+ schema_slug: `custom-fields` (string) - Human-readable schema identifier (recommended alternative to $schema)
+ $schema: `urn:uuid:b3427c65-5383-438b-9c8e-409753b08b4e` (string) - JSON Schema UUID identifier (use schema_slug for easier management)
+ key: `custom-fields` (string) - JSON Schema key for this custom field (deprecated, use schema_slug)
+ version: 0 (number) - Current version for this field, used when updating data fields to avoid changing stale data
+ value (object) - Custom value which will be validated against the JSON Schema assoicated with this data field

## PersonResponseResource (PersonResourceIdentifier)
+ attributes (PersonResponseAttributes)
+ relationships (object)
    + phones (object)
        + data (array)
            + (PhoneResourceIdentifier)
    + emails (object)
        + data (array)
            + (EmailResourceIdentifier)
    + addresses (object)
        + data (array)
            + (AddressResourceIdentifier)
    + web_addresses (object)
        + data (array)
            + (WebAddressResourceIdentifier)
    + orders (object)
        + data (array)
            + (OrderResourceIdentifier)
    + groups (object)
        + data (array)
            + (GroupResourceIdentifier)
    + roles (object)
        + data (array)
            + (RoleResourceIdentifier)
    + received_messages (object)
        + links (object)
            + related: `/people/30d533a5-fb74-499f-90b2-54bd8515f2a5/messages` (string)
    + json_schemas_available (object)
        + data (array)
            + (JsonSchemaResourceIdentifier)
    + touchpoints (object)
        + links (object)
            + related: `/people/30d533a5-fb74-499f-90b2-54bd8515f2a5/touchpoints` (string)
    + comments (object)
        + links (object)
            + related: `/people/30d533a5-fb74-499f-90b2-54bd8515f2a5/comments` (string)
        + meta (object)
            + total_count: `10` (number)
    + pinned_comments (object)
        + data (array)
            + (CommentResourceIdentifier)

## PersonRequestResource (object)
+ type: `people` (string) - Type of resource. Must have a value of 'people'
+ attributes (PersonAttributes)
  + communications_double_opt_in: `false` (boolean) - When creating a person, this attribute can be set to true, in combination with data -> communications -> email set to false, will set the person in the pending state and they will receive a double opt-in email for the configured communications platform (e.g. Mailchimp).
  + user (object) - Optional user details
        + password: ex@mplePassword (string, required) - Password
        + password_confirmation: ex@mplePassword (string, required) - Password Confirmation
        + confirmed_at: `2018-03-26T00:00:00.000Z` (string) - Admin Only: Bypass email confirmation
        + skip_confirmation_notification: true (boolean) - Skip sending confirmation email when creating user
+ relationships (object)
    + emails (object)
        + data (array)
            + (object)
                + type: `emails` (string) - Type of resource. At least 'emails' is required
                + attributes (object)
                    + address: `john.smith@acme.com` (string) - The email address to be used as unique identifier for the person record

## PersonResourceIdentifier2 (object)
+ id: `85b01e60-1b17-45c2-afbf-c4226753c3d1` (required) - Person's uuid
+ type: `people` (required) - Resource Type

## PersonAttributes2 (object)
+ given_name: `Jane` - Given name of the person (first)
+ family_name: `Doe` - Family name of the person (last)
+ additional_name: `J.D.` - Used for initials in Wicket admin
+ alternate_name: `Jane` - Alternate name
+ full_name: `Jane Doe` - Full name (first and last)
+ identifying_number: `23456` (number) - Unique number that can be used to identify the person record
+ slug: `jan` - Slug. Currently populates with alternate name in lowercase
+ gender: `woman` - Gender
+ honorific_prefix: `Mrs.`  - Used for salutation in Wicket admin
+ honorific_suffix: `Ph.D` - Honorific suffix (not used in Wicket admin)
+ preferred_pronoun: `her-hers` - Preferred pronoun
+ job_title: `Back End Developer` - Job Title
+ birth_date: `1900-01-01` - Birth date
+ language: en, fr (enum) - Primary language. If a value other than "en" or "fr" is provided, the person will not be considered valid.
+ languages_spoken: `english` (array) - Languages spoken (not used in Wicket admin)
+ languages_written: `english` (array) - Languages written (not used in Wicket admin)
+ data_fields (array[PersonAttributesDataField]) - Custom fields stored on the person record, validated against JSON Schema

## PersonResponseAttributes2 (PersonAttributes2)
+ uuid: `85b01e60-1b17-45c2-afbf-c4226753c3d1`
+ created_at: `2017-07-27T22:21:26.824Z` - ISO 8601 of creation time
+ updated_at: `2017-07-27T22:47:45.129Z` - ISO 8601 of last modification
+ membership_number: `1234` - Membership number
+ membership_began_on: `1900-01-01` - Date of start of membership
+ tags (array)
+ data (object)
    + communications (object)
        + email: true (boolean) - Whether the user whiches to receive notifications
        + sublists (object)
            + one: true (boolean) - Sublist one preference
            + two: true (boolean) - Sublist two preference
            + three: true (boolean) - Sublist three preference
            + four: true (boolean) - Sublist four preference
            + five: true (boolean) - Sublist five preference
        + sync_services: true (boolean) - Whether the user data will be synced to other services (i.e. mailchimp)
        + journal_physical_copy: true (boolean) - Internal use
+ role_names: `user,member` (array)
+ user (object)
    + email: `jdoe@email.ca` (string)
    + username: `jdoe` (string)
    + reset_password_sent_at: `2018-03-26T00:00:00.000Z` (string)
    + confirmation_sent_at: `2018-03-26T00:00:00.000Z` (string)
    + confirmed_at: `2018-03-26T00:00:00.000Z` (string)

## PersonAttributesDataField2

+ schema_slug: `custom-fields` (string) - Human-readable schema identifier (recommended alternative to $schema)
+ $schema: `urn:uuid:b3427c65-5383-438b-9c8e-409753b08b4e` (string) - JSON Schema UUID identifier (use schema_slug for easier management)
+ key: `custom-fields` (string) - JSON Schema key for this custom field (deprecated, use schema_slug)
+ version: 0 (number) - Current version for this field, used when updating data fields to avoid changing stale data
+ value (object) - Custom value which will be validated against the JSON Schema assoicated with this data field

## PersonResponseResource2 (PersonResourceIdentifier2)
+ attributes (PersonResponseAttributes2)
+ relationships (object)
    + phones (object)
        + data (array)
            + (PhoneResourceIdentifier)
    + emails (object)
        + data (array)
            + (EmailResourceIdentifier)
    + addresses (object)
        + data (array)
            + (AddressResourceIdentifier)
    + web_addresses (object)
        + data (array)
            + (WebAddressResourceIdentifier)
    + orders (object)
        + data (array)
            + (OrderResourceIdentifier)
    + groups (object)
        + data (array)
            + (GroupResourceIdentifier)
    + roles (object)
        + data (array)
            + (RoleResourceIdentifier)
    + received_messages (object)
        + links (object)
            + related: `/people/85b01e60-1b17-45c2-afbf-c4226753c3d1/messages` (string)
    + json_schemas_available (object)
        + data (array)
            + (JsonSchemaResourceIdentifier)
    + touchpoints (object)
        + links (object)
            + related: `/people/85b01e60-1b17-45c2-afbf-c4226753c3d1/touchpoints` (string)
    + comments (object)
        + links (object)
            + related: `/people/85b01e60-1b17-45c2-afbf-c4226753c3d1/comments` (string)
        + meta (object)
            + total_count: `10` (number)
    + pinned_comments (object)
        + data (array)
            + (CommentResourceIdentifier)

## SchemaResponse (object)
+ id: `78768b11-f26a-4bcf-a2f0-14482a977ae1`
+ type: `json_schemas`
    + attributes (object)
        + key: `personal_info`
        + schema (object)
            + $id: `urn:uuid:78768b11-f26a-4bcf-a2f0-14482e8877ae`
            + $schema: `http://json-schema.org/draft-06/schema#`
            + properties (object)
                + title: `schema_title`
                + type: `string`
        + ui_schemas_for_refs: []
        + version: null

## PersonIncludes (array)

### Items
+ (OrganizationResponseResource, required)
+ (PersonEmailResponseResource, required)
+ (PersonPhoneResponseResource, required)
+ (PersonAddressResponseResource, required)
+ (RoleResponseResource, required)


## PersonEmailTakenErrorItem (object)

+ status: 422 (number) - HTTP status code
+ title: This email is already assigned to another user as a primary or unique address (string) - Error title
+ meta (object) - Additional error metadata
    + value: jsmith@email.ca (string) - The conflicting email value
    + taken_by (PersonResourceIdentifier) - Details about the entity that has taken this email
    + claimable: true (boolean) - Whether the email is claimable
    + error: taken (string) - Type of error
    + field: emails.address (string) - Field in conflict


## PersonEmailTakenResponse (object)

+ message: Record conflict (string) - General message describing the error
+ errors (array[PersonEmailTakenErrorItem]) - List of error details

## OrganizationResourceIdentifier (object)
+ id: `73cd91c1-76e4-4421-84df-acf1452cb8bc` (required) - Organization's uuid
+ type: `organizations` (required) - Resource Type

## OrganizationAttributes (object)
+ type: `Education` (string) - The type of organization from a enumerable list
+ legal_name: `Acme University` (string) - The name of the organization
+ legal_name_en: `Acme University` (string) - The name of the organization in English (set to legal name, not used by admin interface)
+ legal_name_fr: `Acme University` (string) - The name of the organization in french (set to legal name, not used by admin interface)
+ legal_name_es: `Acme University` (string) - The name of the organization in spanish (set to legal name, not used by admin interface)
+ alternate_name: `UofA` (string) - The alternate name of the organization
+ alternate_name_en: `UofA` (string) - The alternate name of the organization in English (set to alternate name, not used by admin interface)
+ alternate_name_fr: `UofA` (string) - The alternate name of the organization in french (set to alternate name, not used by admin interface)
+ alternate_name_es: `UofA` (string) - The alternate name of the organization in spanish (set to alternate name, not used by admin interface)
+ description: `Acme University description` (string) - The description of the organization
+ description_en: `Acme University description` (string) - The description of the organization in English (set to description, not used by admin interface)
+ description_fr: `Acme University description` (string) - The description of the organization in french (set to description, not used by admin interface)
+ description_es: `Acme University description` (string) - The description of the organization in spanish (set to description, not used by admin interface)
+ identifying_number: `34567` (number) - Unique number that can be used to identify the organization record
+ data_fields (array[OrganizationAttributesDataField]) - Custom fields stored on the organization record, validated against JSON Schema

## OrganizationResponseAttributes (OrganizationAttributes)
+ uuid: `73cd91c1-76e4-4421-84df-acf1452cb8bc`
+ created_at: `2017-07-27T22:21:26.824Z` - ISO 8601 of creation time
+ updated_at: `2017-07-27T22:47:45.129Z` - ISO 8601 of last modification
+ slug: `acme-university` - The slug for the organization entry
+ destroyable: true (boolean) - Whether deletion is allowed
+ ancestry: 1 (number) - How many ancestors in the hierarchy this record has
+ people_count: 0 (number) - Number of people that belong to this organization
+ duns: null
+ assignable_role_names: [] (array)
+ data_fields: (array[OrganizationAttributesDataField])
+ tags: [] (array)
+ data (object)
    + key1: `value1` (string) - Example custom data 1
    + key2: `value2` (string) - Example custom data 2
+ inheritable_from_parent (array)
+ inherits_from_parent (object)

## OrganizationAttributesDataField

+ schema_slug: `org-custom-fields` (string) - Human-readable schema identifier (recommended alternative to $schema)
+ $schema: `urn:uuid:e7596ac2-77aa-4983-b98d-e7049b4d08e1` (string) - JSON Schema UUID identifier (use schema_slug for easier management)
+ key: `custom-fields` (string) - JSON Schema key for this custom field (deprecated, use schema_slug)
+ version: 0 (number) - Current version for this field, used when updating data fields to avoid changing stale data
+ value (object) - Custom value which will be validated against the JSON Schema assoicated with this data field

## OrganizationResponseResource (OrganizationResourceIdentifier)
+ attributes (OrganizationResponseAttributes)
+ meta (object)
  + ancestry_depth: `1` (number)
+ relationships (object)
    + addresses (object)
        + data (array)
            + (AddressResourceIdentifier)
    + child_organizations (object)
        + meta: {}
    + comments (object)
        + links (object)
            + related: `/organizations/30d533a5-fb74-499f-90b2-54bd8515f2a5/comments` (string)
        + meta (object)
            + total_count: `0` (number)
    + connections (object)
        + links (object)
            + related: `/organizations/30d533a5-fb74-499f-90b2-54bd8515f2a5/connections` (string)
    + emails (object)
        + data (array)
            + (EmailResourceIdentifier)
    + json_schemas_available (object)
        + data (array)
            + (JsonSchemaResourceIdentifier)
    + parent_organization (object)
        + data (array)
            + (OrganizationResourceIdentifier)
    + phones (object)
        + data (array)
            + (PhoneResourceIdentifier)
    + pinned_comments (object)
        + data (array)
            + (CommentResourceIdentifier)
    + roles (object)
        + data (array)
            + (RoleResourceIdentifier)
    + web_addresses (object)
        + data (array)
            + (WebAddressResourceIdentifier)

## OrganizationRequestResource (object)
+ type: `organizations` (string) - Type of resource. Must have a value of 'organizations'
+ attributes (OrganizationAttributes)
+ relationships (object)
  + parent_organization (object)
      + data (OrganizationResourceIdentifier)


## OrganizationIncludes (array)

### Items
+ (RoleResourceIdentifier)
+ (JsonSchemaResourceIdentifier, required)
## ConnectionResourceIdentifier (object)
+ id: `7f93c975-c1dc-46d1-9562-1df3b24b0c6d` (required) - Connection's uuid
+ type: `connections` (required) - Resource type

## ConnectionAttributes (object)
+ description: `This person is the manager` - Description of the connection between the two entities.
+ `connection_type`: `person_to_person`, `person_to_organization` (enum) - Identifies the type of connection to be returned
+ starts_at: `2018-01-01T00:00:00.000Z` - ISO 8601 of the time when the connection starts to take effect
+ ends_at: `2018-12-31T00:00:00.000Z` - ISO 8601 of the time when the connection is no longer in effect
+ type: `co-worker` (required) - String description of the type of connection (slug of a Resource Type)
+ tags: team member, division 1 (array[string]) - List of tags used to categorize the connection

## ConnectionRelationships
+ person (optional) - Legacy attribute used along side the organization resource to create/ update a Person to Organization relationship. 
    + data
        + type: `people` (string)
        + id (string)
+ organization (optional) - Legacy attribute used along side the person resource to create/ update a Person to Organization relationship. 
    + data 
        + type: `organizations` (string)
        + id (string)
+ to (optional) - New attribute used along side the from resource to create/ update either Person to Organization or Person to Person relationships. 
    + data
        + type: `people`, `organizations` (enum)
        + id (string)
+ from (optional) - New attribute used along side the from resource to create/ update either Person to Organization or Person to Person relationships. 
    + data
        + type: `people`, `organizations` (enum)
        + id: `4ad4e07c-978e-4465-b289-f8905551607d` (string)

## ConnectionResponseAttributes
+ uuid: `7f93c975-c1dc-46d1-9562-1df3b24b0c6d`
+ created_at: `2017-07-27T22:21:26.824Z` - ISO 8601 of creation time
+ updated_at: `2017-07-27T22:47:45.129Z` - ISO 8601 of last modification
+ Attributes
    + attributes (ConnectionAttributes)
    + relationships (ConnectionRelationships)

## ConnectionResponseResource (ConnectionResourceIdentifier)
+ attributes (ConnectionResponseAttributes)
+ relationships (object)
    + organization
    + person
    + to (object)
        + data (PersonResourceIdentifier2)
    + from (object)
        + data (PersonResourceIdentifier)
    + resource_tags (object)
        + links (object)
            + related: `/resource_tags/connections` (Fragment) - Relative path for fetching all tags related to the connections resource type

## ConnectionRequestResource (object)
+ attributes (ConnectionAttributes)
+ relationships (object)
    + to (object)
        + data (PersonResourceIdentifier2)
    + from (object)
        + data (PersonResourceIdentifier

## ConnectionIncludes (array)

Connection included objects are the full resource representations of all people and organizations which are related to the connections in the current request.

### Items
+ (PersonResponseResource, required)
+ (OrganizationResponseResource, required)
+ (PersonResponseResource2, required)
## PhoneResourceIdentifier (object)
+ id: `4a1e4827-00ac-4576-b383-39c492ccf029` (required) - Phone's uuid
+ type: `phones` (required) - Resource Type

## PhoneAttributes (object)
+ type: `work` (string) - The type of the phone record
+ number: `+16131234526` (string) - The phone number
+ number_national_format: `(613) 123-4526` (string) - National format
+ number_international_format: `+1 (613) 123-4526` (string) - International format
+ extension (string) - Extension number
+ country_code_number: `1` (number) - Country code
+ primary: `true` (boolean) - Is the address flagged as primary
+ consent: `false` (boolean) - Consent
+ consent_third_party: `false` (boolean) - Consent of third party
+ primary_sms: `false` (boolean) - Is this flagged to receive sms messages


## PhoneResponseAttributes (PhoneAttributes)
+ uuid: `4a1e4827-00ac-4576-b383-39c492ccf029`
+ created_at: `2017-07-27T22:21:26.824Z` - ISO 8601 of creation time
+ updated_at: `2017-07-27T22:47:45.129Z` - ISO 8601 of last modification
+ deleted_at: `2017-07-27T22:47:45.129Z` - ISO 8601 of soft deletion

## PhoneRelationshipsAttributes (object)
+ organization (object)
    + data (OrganizationResourceIdentifier)

## PersonPhoneResponseResource (PhoneResourceIdentifier)
+ attributes (PhoneResponseAttributes)
+ relationships (PhoneRelationshipsAttributes)
    + phoneable (object)
        + data (PersonResourceIdentifier)

## OrganizationPhoneResponseResource (PhoneResourceIdentifier)
+ attributes (PhoneResponseAttributes)
+ relationships (PhoneRelationshipsAttributes)
    + phoneable (object)
        + data (OrganizationResourceIdentifier)


## PhoneRequestResource (object)
+ attributes (PhoneAttributes)

## PhoneIncludes (array)

### Items
+ (PersonResponseResource, required)

## EmailResourceIdentifier (object)
+ id: `4beb895d-3703-4400-876b-a1a985aebf57` (required) - Email's uuid
+ type: `emails` (required) - Resource Type

## EmailAttributes (object)
+ localpart: `john` (string) - The user name part of the email address
+ domain: `acme.ca` (string) - The domain part of the email address
+ type: `work` (string) - The type of the email record
+ address: `john@acme.ca` (string) - The full email address
+ primary: `true` (boolean) - Is the email address flagged as primary
+ consent: `false` (boolean) - Consent
+ consent_third_party: `false` (boolean) - Consent of third party
+ unique: `true` (boolean) - Indicates that the address should be treated as unique within Wicket for the resource's type `(`e.g. be unique across all people in Wicket`)`. When primary is set to true, unique will be forced to true. If primary is set to false, unique will default to true, unless otherwise indicated.

## EmailResponseAttributes (EmailAttributes)
+ uuid: `4beb895d-3703-4400-876b-a1a985aebf57`
+ created_at: `2017-07-27T22:21:26.824Z` - ISO 8601 of creation time
+ updated_at: `2017-07-27T22:47:45.129Z` - ISO 8601 of last modification
+ deleted_at: `2017-07-27T22:47:45.129Z` - ISO 8601 of soft deletion

## EmailRelationshipsAttributes (object)
+ organization (object)
    + data (OrganizationResourceIdentifier)

## PersonEmailResponseResource (EmailResourceIdentifier)
+ attributes (EmailResponseAttributes)
+ relationships (EmailRelationshipsAttributes)
    + emailable (object)
        + data (PersonResourceIdentifier)

## OrganizationEmailResponseResource (EmailResourceIdentifier)
+ attributes (EmailResponseAttributes)
+ relationships (EmailRelationshipsAttributes)
    + emailable (object)
        + data (OrganizationResourceIdentifier)


## EmailRequestResource (object)
+ attributes (EmailAttributes)

## EmailIncludes (array)

### Items
+ (PersonResponseResource, required)

## AddressResourceIdentifier (object)
+ id: `df1110d1-df3d-4a9c-8355-b82e25832a0e` (required) - Address's uuid
+ type: `addresses` (required) - Resource Type

## AddressAttributes (object)
+ type: `home` (string) - The type of address record
+ company_name: `Wicket Inc` (string) - Company name field of the address record
+ city: `Ottawa` (string) - The city
+ zip_code: `K1Z 6X6` (string) - The zip/postal code
+ address1: `123 Wicket Lane` (string) - Address line 1
+ address2: `Top Floor` (string) - Address line 2
+ state_name: `ON` (string) - State or province
+ country_code: `CA` (string) - Country code
+ country_name: `Canada` (string) - Country name
+ formatted_address_label: `Wicket Inc - 123 Wicket Lane Ottawa - Top Floor, ON K1Z 6X6 Canada` (string) - Formatted version of the address with new lines
+ latitude: `null` (string) - Latitud
+ longitude: `null` (string) - Longitud
+ active: `true` (boolean) - Is the address active
+ primary: `true` (boolean) - Is the address flagged as primary
+ consent: `false` (boolean) - Consent
+ consent_third_party: `false` (boolean) - Consent of third party

## AddressResponseAttributes (AddressAttributes)
+ uuid: `df1110d1-df3d-4a9c-8355-b82e25832a0e`
+ created_at: `2017-07-27T22:21:26.824Z` - ISO 8601 of creation time
+ updated_at: `2017-07-27T22:47:45.129Z` - ISO 8601 of last modification
+ deleted_at: `2017-07-27T22:47:45.129Z` - ISO 8601 of soft deletion

## AddressRelationshipsAttributes (object)
+ organization (object)
    + data (OrganizationResourceIdentifier)
+ cloned_from (object)
    + data (AddressResourceIdentifier)
    + meta (object)
        + has_same_address_fields: `false` (boolean)

## PersonAddressResponseResource (AddressResourceIdentifier)
+ attributes (AddressResponseAttributes)
+ relationships (AddressRelationshipsAttributes)
    + addressable (object)
        + data (PersonResourceIdentifier)

## OrganizationAddressResponseResource (AddressResourceIdentifier)
+ attributes (AddressResponseAttributes)
+ relationships (AddressRelationshipsAttributes)
    + addressable (object)
        + data (OrganizationResourceIdentifier)

## AddressRequestResource (object)
+ attributes (AddressAttributes)


## AddressIncludes (array)

### Items
+ (PersonResponseResource, required)
## WebAddressResourceIdentifier (object)
+ id: `a02d812a-796a-4c3d-84a2-ba3d25cbe104` (required) - WebAddress's uuid
+ type: `web_addresses` (required) - Resource Type

## WebAddressAttributes (object)

## WebAddressResponseAttributes (WebAddressAttributes)

## WebAddressResponseResource (WebAddressResourceIdentifier)

## WebAddressRequestResource (object)

## WebAddressIncludes (array)

### Items
## OrderResourceIdentifier (object)
+ id: `e50ae451-5303-4122-aef6-04a3a4eb6146` (required) - Order's uuid
+ type: `orders` (required) - Resource Type

## OrderAttributes (object)

## OrderResponseAttributes (OrderAttributes)

## OrderResponseResource (OrderResourceIdentifier)

## OrderRequestResource (object)

## OrderIncludes (array)

### Items
## GroupMemberResourceIdentifier (object)
+ id: `8cb57ad8-895f-43f7-a3e2-013808859ea1` - group member uuid
+ type: `group_members` (required)

## GroupMemberAttributes (object)
+ uuid: `8cb57ad8-895f-43f7-a3e2-013808859ea1`
+ type: `member` (enum) - The type of group member from an enumerable list
+ start_date: `2023-01-01` (string) - The date a group member was/is added to a group
+ end_date: `2023-12-31` (string) - The date a group member will be / was removed from a group
+ external_id: `46f25a4e-8b86-419f-892d-f8b8e79d8ca9` (string) - The external ID of this group
+ identifying_number: `12345` (number) - Unique number that can be used to identify the group member entity
+ created_at: `2023-01-01T00:00:00.000Z` - ISO 8601 of creation time
+ updated_at: `2023-06-15T00:00:00.000Z` - ISO 8601 of last modification
+ active: `true` (boolean) - Computed status of this group member based on the start_date / end_date values.

## GroupMemberCreateOrUpdateAttributes (object)
+ person_id: `f42cfef2-13d7-4963-bfe8-a932ffc32427` - person uuid
+ groups_id: `2bfa2f56-6b9a-4fe4-bd11-e2f9919b9933` - group uuid
+ type: `member` (enum) - The type of group member from an enumerable list
+ external_id: `46f25a4e-8b86-419f-892d-f8b8e79d8ca9` (string) - The external ID of this group
+ start_date: `2023-01-01` (string) - The date a group member was/is added to a group 
+ end_date: `2023-12-31` (string) - The date a group member will be / was removed from a group

## GroupMemberRelationships (object)
+ group (required) - Group that corresponds to this group member entity
    + data (GroupResourceIdentifier)
+ person (required) - Person that corresponds to this group member entity
    + data (PersonResourceIdentifier)

## GroupMemberResponseResource (GroupMemberResourceIdentifier)
+ attributes (GroupMemberAttributes)
+ relationships (object)
    + group (object)
        + data (GroupResourceIdentifier)
    + person (object)
        + data (PersonResourceIdentifier)

## GroupMemberRequestResource (object)
+ id: `null`
+ type: `group_members` (string) - Type of resource. Must have a value of 'group_members'
+ attributes (GroupMemberCreateOrUpdateAttributes)
+ relationships (object)
    + group (object)
        + data (object)
            + id: `2bfa2f56-6b9a-4fe4-bd11-e2f9919b9933` (string) - uuid of corresponding group entity, required
            + type: `groups` (string) - Type of resource, must be "groups"
    + person (object)
        + data (object)
            + id: `f42cfef2-13d7-4963-bfe8-a932ffc32427` (string) - uuid of corresponding person entity, required
            + type: `people` (string) - Type of resource, must be "people"
        
## GroupMemberIncludes (array)

### Items
+ (PersonResponseResource, required)
+ (GroupResponseResource, required)
## GroupResourceIdentifier (object)
+ id: `2bfa2f56-6b9a-4fe4-bd11-e2f9919b9933` (required) - Group's uuid
+ type: `groups` (required) - Resource Type

## GroupAttributes (object)
+ name: `Volunteers` (string) - The name of the group
+ name_en: `Volunteers` (string) - The name of the group in English
+ name_fr: `Volontaires` (string) - The name of the group in French
+ name_es: `Voluntarios` (string) - The name of the group in Spanish
+ description: `Volunteer group description` (string) - The description of the group
+ description_en: `Volunteer group description` (string) - The description of the group in English
+ description_fr: `Volunteer group description` (string) - The description of the group in French
+ description_es: `Volunteer group description` (string) - The description of the group in Spanish
+ type: `committee` (enum) - The type of group from an enumerable list
+ start_date: `2023-03-15` (string) - Optional. The date when the group term should start. A null value means the start date is open-ended.
+ end_date: `2023-12-15` (string) - Optional. The date when the group term should end. A null value means the end date is open-ended.
+ identifying_number: `12345` (number) - The unique number that can be used to identify the group record
+ web_address_attributes: (object) - Web address associated with this group entity (create / update only)
    + address: `https://wicket.io/` (string)
    + type: `website` (enum) - The type of web address
+ address_attributes: (object) - Physical address associated with this group entity (create / update only)
    + company_name: `Wicket` (string)
    + department: `Sales` (string)
    + division: `Marketing Division` (string)
    + address1: `123 Fake Street` (string)
    + address2: `246 Fake Street` (string)
    + city: `Ottawa` (string)
    + state_name: `ON` (string) - State or Province code
    + country_code: `CA` (string) - Country code
    + zip_code: `A1A1A1` (string)

## GroupCreateOrUpdateAttributes (object)
+ name: `Volunteers` (string) - The name of the group
+ name_en: `Volunteers` (string) - The name of the group in English
+ name_fr: `Volontaires` (string) - The name of the group in French
+ name_es: `Voluntarios` (string) - The name of the group in Spanish
+ description: `Volunteer group description` (string) - The description of the group
+ description_en: `Volunteer group description` (string) - The description of the group in English
+ description_fr: `Volunteer group description` (string) - The description of the group in French
+ description_es: `Volunteer group description` (string) - The description of the group in Spanish
+ external_id: `46f25a4e-8b86-419f-892d-f8b8e79d8ca9` (string) - The external ID of this group
+ start_date: `2023-03-15` (string) - Optional. The date when the group term should start. A null value means the start date is open-ended.
+ end_date: `2023-12-15` (string) - Optional. The date when the group term should end. A null value means the end date is open-ended.
+ type: `committee` (enum) - The type of group from an enumerable list
+ web_address_attributes: (object) - Web address associated with this group entity
    + address: `https://wicket.io/` (string)
    + type: `website` (enum) - The type of web address
+ address_attributes: (object) - Physical address associated with this group entity
    + company_name: `Wicket` (string)
    + department: `Sales` (string)
    + division: `Marketing Division` (string)
    + address1: `123 Fake Street` (string)
    + address2: `246 Fake Street` (string)
    + city: `Ottawa` (string)
    + state_name: `ON` (string) - State or Province code
    + country_code: `CA` (string) - Country code
    + zip_code: `A1A1A1` (string)

# GroupCreateOrUpdateRelationships (object)
+ organization (optional) - Organization associated with this group entity
    + data
        + type: `organizations` (string)
        + id: `4ad4e07c-978e-4465-b289-f8905551607d`
+ administrators (optional) -  Administrators associated with this group entity
    + data (array)
        + (object)
            + type: `people` (string)
            + id: `4ad4e07c-978e-4465-b289-f8905551607d`

## GroupRelationships (object)
+ organization (optional) - Organization associated with this group entity
    + data
        + type: `organizations` (string)
        + id: `4ad4e07c-978e-4465-b289-f8905551607d`
+ web_address (optional) -  Web address associated with this group entity
    + data
        + type: `web_addresses` (string)
        + id: `4ad4e07c-978e-4465-b289-f8905551607d`
+ address (optional) -  Physical address associated with this group entity
    + data
        + type: `addresses` (string)
        + id: `4ad4e07c-978e-4465-b289-f8905551607d`
+ administrators (optional) -  Administrators associated with this group entity
    + data (array)
        + (object)
            + type: `people` (string)
            + id: `4ad4e07c-978e-4465-b289-f8905551607d`

## GroupResponseAttributes
+ uuid: `2bfa2f56-6b9a-4fe4-bd11-e2f9919b9933`
+ name: `Volunteers` (string) - The name of the group
+ name_en: `Volunteers` (string) - The name of the group in English
+ name_fr: `Volontaires` (string) - The name of the group in French
+ name_es: `Voluntarios` (string) - The name of the group in Spanish
+ description: `Volunteer group description` (string) - The description of the group
+ description_en: `Volunteer group description` (string) - The description of the group in English
+ description_fr: `Volunteer group description` (string) - The description of the group in French
+ description_es: `Volunteer group description` (string) - The description of the group in Spanish
+ external_id: `46f25a4e-8b86-419f-892d-f8b8e79d8ca9` (string) - The external ID of this group
+ type: `committee` (enum) - The type of group from an enumerable list
+ slug: `volunteers` (string) - The slug for the group entry
+ start_date: `2023-03-15` (string) - Optional. The date when the group term should start. A null value means the start date is open-ended.
+ end_date: `2023-12-15` (string) - Optional. The date when the group term should end. A null value means the end date is open-ended.
+ identifying_number: `12345` (number) - The unique number that can be used to identify the group record
+ created_at: `2023-03-15T22:21:26.824Z` (string) - ISO 8601 of creation time
+ updated_at: `2023-03-15T22:21:26.824Z` (string) - ISO 8601 of last modification
+ active: `true` (boolean) - Flag indicating if this group is currently active
+ active_member_count: `51` (number) - Number of currently active members in this group

## GroupResponseResource (GroupResourceIdentifier)
+ attributes (GroupResponseAttributes)
+ relationships (object)
    + organization (object)
        + data (OrganizationResourceIdentifier)
    + web_address (object)
        + data (WebAddressResourceIdentifier)
    + address (object)
        + data (AddressResourceIdentifier)
    + administrators (object)
        + data (array)
            + (PersonResourceIdentifier)

## GroupRequestResource (object)
+ type: `groups` (string) - Type of resource. Must have a value of `groups`
+ attributes (GroupCreateOrUpdateAttributes)
+ relationships (GroupCreateOrUpdateRelationships)

## GroupIncludes (array)

### Items
+ (OrganizationResponseResource)
+ (PersonResponseResource)
## JsonSchemaResourceIdentifier (object)
+ id: `ff686501-2ac6-43bd-9f10-b0c7d8d8ae04` (required) - JsonSchema's uuid
+ type: `json_schemas` (required) - Resource Type

## JsonSchemaAttributes (object)

## JsonSchemaResponseAttributes (JsonSchemaAttributes)

## JsonSchemaResponseResource (JsonSchemaResourceIdentifier)

## JsonSchemaRequestResource (object)

## JsonSchemaIncludes (array)

### Items
## CommentResourceIdentifier (object)
+ id: `2225434d-50b2-4402-9fb7-227d8ec2620c` (required) - Comment's uuid
+ type: `comments` (required) - Resource Type

## CommentAttributes (object)

## CommentResponseAttributes (CommentAttributes)

## CommentResponseResource (CommentResourceIdentifier)

## CommentRequestResource (object)

## CommentIncludes (array)

### Items
## TouchpointResourceIdentifier (object)
+ id: `ea03ad13-ad3a-4058-88df-c365ed68c5fd` (required) - Touchpoint's uuid
+ type: `touchpoints` (required) - Resource type

## TouchpointAttributes (object)
+ action: `Attended Event` (string) - Description of the general action taken
+ details: `Members checked-in at Conference X` (string) - More details about the specific action. It provides more context
+ code: `EventBrite:checkin` (string) - Custom action code
+ created_at: `2021-07-27T22:21:26.824Z` (string, optional)  - Optional. Defaults to now.
   

## TouchpointResponseAttributes (TouchpointAttributes)
+ uuid: `ea03ad13-ad3a-4058-88df-c365ed68c5fd`
+ created_at: `2017-07-27T22:21:26.824Z` - ISO 8601 of creation time
+ updated_at: `2017-07-27T22:47:45.129Z` - ISO 8601 of last modification

## TouchpointResponseResource (TouchpointResourceIdentifier)
+ attributes (TouchpointResponseAttributes)
+ relationships (object)
    + person (object)
        + data (PersonResourceIdentifier)
    + service (object)
        + data (ServiceResourceIdentifier)

## TouchpointRequestResource (object)
+ attributes (TouchpointAttributes)
    + data (object) - An arbitrary JSON object with custom data you want to store
+ relationships (object)
    + person (object)
        + data (PersonResourceIdentifier)
    + service (object)
        + data (ServiceResourceIdentifier)

## TouchpointIncludes (array)

Touchpoint included objects are the full resource representations of all people and services which are related to the touchpoints in the current request.

### Items
+ (PersonResponseResource, required)
+ (ServiceResponseResource, required)

## TouchpointStats (object)
+ touchpoints_by_month (array) - An array of 12 subarrays of the form: [month_unix_timestamp, total_count] for the last 12 months
    + (array)
        + `1522555200` - The unix timestamp for the month
        + `3` - The total count for the month
+ top_service (object)
    + service_id: `8af0dff3-c03f-4f39-8ca2-550dfaf7db38` - UUID of the service
    + service_name: `Wicket` - The name of the service
    + count: `23` - The total count of touchpoints for the top service

## TouchpointFacets (object)
+ service_uuid_eq (array)
    + (object)
        + value: `8af0dff3-c03f-4f39-8ca2-550dfaf7db38` - Service UUID
        + label: `Authentication` - The name of the service (i.e. Eventbrite, Litmos, Mailchimp, Wicket etc.)
+ action_eq (array)
    + (object)
        + value: `Created Account` - The name of a possible action taken
        + label: `Created Account` - The label of a possible action taken
+ interval_uuid_eq (array)
    + (object)
        + value: `30c98098-a94b-4248-8e99-db77e15f6b75` - The UUID of the membership interval
        + label: `Annual Membership 2018` - The label of the membership interval
+ organization_uuid_eq (array)
    + (object)
        + value: `73cd91c1-76e4-4421-84df-acf1452cb8bc` - The UUID of the organization
        + label: `National Association of Acme Industries` - The name of the organization
        + group: `Industry` - The name of the group for the organization
+ membership_uuid_eq (array)
    + (object)
        + value: `7b260726-515b-40ec-98dd-c1806ad180ff` - The UUID of the membership type
        + label: `Active Acme Associate` - The label of the membership type
## ServiceResourceIdentifier (object)
+ id: `8af0dff3-c03f-4f39-8ca2-550dfaf7db38` (required) - Service's uuid
+ type: `services` (required) - Resource type

## ServiceAttributes (object)
+ name: `Wicket` (string) - The name of the service
+ description: `Wicket System` (string) - The description of the service
+ endpoint_url: null (string) - The end point to consume the service (if applicable)
+ active: `true` (boolean) - Flag to indicate if the service is active
+ slug: `wicket` (string) - The slug of the service

## ServiceResponseAttributes (ServiceAttributes)
+ uuid: `8af0dff3-c03f-4f39-8ca2-550dfaf7db38`

## ServiceResponseResource (ServiceResourceIdentifier)
+ attributes (ServiceResponseAttributes)
+ relationships (object)
  + organization (object)
        + data (OrganizationResourceIdentifier)
## ResourceTagsResourceIdentifier (object)
+ id: `connections` (ResourceTagsTypes, required) - ID for resource tags type, maps to other higher level resource types in Wicket.
+ type: `resource_tags` (string, required) - Resource type

## ResourceTagsAttributes (object)

+ tags (array[ResourceTagsEntry], required, fixed-type) - Array of tags and counts for the resource types.
+ custom_context_example (array[ResourceTagsEntry], fixed-type) - Array of tags and their counts

## ResourceTagsResponseAttributes (ResourceTagsAttributes)

## ResourceTagsResponseResource (ResourceTagsResourceIdentifier)

+ attributes (ResourceTagsResponseAttributes)

## ResourceTagsTypes (enum[string])
List of resources currently supporting tags.

- connections

## ResourceTagsEntry (object)

+ name: `ceo` (string, required) - The name of the tag
+ count: 4 (number, required) - The number of resources with this tag.
## MembershipResourceIdentifier (object)
+ id: `f8ddce1e-6938-4216-9ce9-f24a6b7cd677` (required) - Membership uuid
+ type: `memberships` (required) - Resource Type

## MembershipAttributes (object)
+ name: `Associate Membership` (string)
+ description: `Associate Membership description` (string)
+ type: `individual` (enum, sample)
    - individual
    - organization
+ active: true (boolean)

## MembershipResponseAttributes (MembershipAttributes)
+ name: `Associate Membership` (string)
+ name_en: `Associate Membership` (string)
+ name_fr: `Associate Membership` (string)
+ name_es: `Associate Membership` (string)
+ description: `Associate Membership description` (string)
+ description_en: `Associate Membership description` (string)
+ description_fr: `Associate Membership description` (string)
+ description_es: `Associate Membership description` (string)
+ code: null,
+ active: true (boolean)
+ data (object)
+ `scheduled_payments`: `scheduled_payments_eligible` (string)
+ refundable: `refundable_eligible` (string)
+ adjustable: `adjustable_eligible` (string)
+ renewable: `renewable_eligible` (string)
+ weight: null
+ approval: `approval_not_required` (string)
+ slug: `associate-membership` (string)
+ max_assignments: `1` (number)
+ unlimited_assignments: false (boolean)
+ leave_public: `eligible` (string)
+ tags (array)


## MembershipResponseResource (MembershipResourceIdentifier)
+ attributes (MembershipResponseAttributes)
+ relationships (object)
    + organization (object)
        + data: null
    + parent (object)
        + data: null
    + tax_category (object)
        + data: null


## MembershipRequestResource (object)
+ type: `memberships` (string) - Type of resource. Must have a value of 'memberships'
+ attributes (MembershipAttributes)


## MembershipIncludes (array)


### Items
+ (OrganizationResponseResource, required)
## PersonMembershipResourceIdentifier (object)
+ id: `224a4ac2-c528-4f36-8ed6-f83195cd79a5` (required) - person membership uuid
+ type: `person_memberships` (required)

## PersonMembershipAttributes (object)

+ starts_at: `2020-01-01T00:00:00.000Z` - ISO 8601 of the time when the this membership entry should start at. A null value means the start date is open-ended.
+ ends_at: `2020-12-31T00:00:00.000Z` - ISO 8601 of the time when the membership entry should end at. A null value means the end date is open-ended.
+ expires_at: `2021-01-31T00:00:00.000Z` - ISO 8601 of the time when the membership expires.
+ grace_period_days: `30` (number) - Number of days for the grace period after expiration.
+ in_grace: `false` (boolean) - Flag indicating if the membership is currently in its grace period.
+ status: `Active` (enum[string]) - Current status of the membership.
    + `Active` - Membership is active
    + `Inactive` - Membership is inactive
    + `Delayed` - Membership is delayed
+ active: `true` (boolean) - Flag indicating if this membership is currently active.
+ data: `{"member_number": "12345", "join_reason": "conference"}` (object) - Custom data associated with the membership (JSON).
+ external_id: `98765` - External identifier for the membership, used when linked to external systems like WooCommerce.
+ created_at: `2019-12-01T00:00:00.000Z` - ISO 8601 timestamp of when the membership was created.
+ updated_at: `2020-06-15T00:00:00.000Z` - ISO 8601 timestamp of when the membership was last updated.
+ deleted_at: `null` - ISO 8601 timestamp of when the membership was deleted (null if not deleted).
+ membership_category: `primary` (string) - Category of the membership. Default is "primary", but can be configured per client (e.g., "national", "branch", "division").

## PersonMembershipRelationships (object)

+ membership - Membership tier associated with this membership entry
    + data (optional, MembershipResourceIdentifier)
+ person - Person this membership entry belongs to
    + data (optional, PersonResourceIdentifier)
+ organization_membership - When present, this relationship indicates this membership entry is assigned to an organization membership, when active this membership will use 1 assignment.
    + data (optional, OrganizationMembershipResourceIdentifier)
+ fusebill_subscription - When present, this membership is managed by a subscription within fusebill.
    + data (optional)
        + type: `fusebill_subscriptions` (required, string)
        + id: `577dcfb9-8ffe-4517-b159-64f57c1a3343` (required, string) - Fusebill subscription uuid

## PersonMembershipResponseResource (PersonMembershipResourceIdentifier)

+ attributes (PersonMembershipAttributes)
+ relationships (PersonMembershipRelationships)
## OrganizationMembershipResourceIdentifier (object)
+ id: `c330d18e-0aa1-404f-9a91-8540817fecf7` (required) - organization membership uuid
+ type: `organization_memberships` (required)

## OrganizationMembershipAttributes (object)

+ starts_at: `2020-01-01T00:00:00.000Z` - ISO 8601 of the time when the this membership entry should start at. A null value means the start date is open-ended.
+ ends_at: `2020-12-31T00:00:00.000Z` - ISO 8601 of the time when the membership entry should end at. A null value means the end date is open-ended.
+ expires_at: `2021-01-31T00:00:00.000Z` - ISO 8601 of the time when the membership expires.
+ grace_period_days: `30` (number) - Number of days for the grace period after expiration.
+ in_grace: `false` (boolean) - Flag indicating if the membership is currently in its grace period.
+ status: `Active` (enum[string]) - Current status of the membership.
    + `Active` - Membership is active
    + `Inactive` - Membership is inactive
    + `Delayed` - Membership is delayed
+ active: `true` (boolean) - Flag indicating if this membership is currently active.
+ membership_category: `primary` (string) - Category of the membership. Default is "primary", but can be configured per client (e.g., "national", "branch", "division").
+ max_assignments: `6`, (number) - When specified indicates this membership entry has restrictions on the number of people that can be assigned. Populated from the membership tier by default.
+ active_assignments_count: `3` (number) - Number of currently active person memberships assigned to this resource.
+ unlimited_assignments: `false` (boolean) - Flag indicating if this membership supports unlimited person assignments.
+ data: `{"department": "engineering", "budget_code": "ENG-2024"}` (object) - Custom data associated with the membership (JSON).
+ external_id: `12345` - External identifier for the membership, used when linked to external systems like WooCommerce.
+ created_at: `2019-12-01T00:00:00.000Z` - ISO 8601 timestamp of when the membership was created.
+ updated_at: `2020-06-15T00:00:00.000Z` - ISO 8601 timestamp of when the membership was last updated.
+ deleted_at: `null` - ISO 8601 timestamp of when the membership was deleted (null if not deleted).
+ is_cascadeable: `true` (boolean) - Flag indicating if the membership can be cascaded to child resources.
+ cascade_type: `vertical` (enum[string]) - Type of cascading behavior for this membership.
    + `vertical` - Cascade to all descendants through the organization hierarchy
    + `horizontal` - Cascade to organization's relationships only
+ cascadeable_resource_types: `employee`, `contractor`, `volunteer` (array[string]) - List of Relationship (Connection) types that will trigger a cascade for membership assignments within this membership record.

## OrganizationMembershipRelationships (object)

+ membership - Membership tier associated with this membership entry
    + data (optional, MembershipResourceIdentifier)
+ organization - Organizaiton associated with this membership
    + data (optional, OrganizationResourceIdentifier)
+ owner - Person record which is the current owner of this organization membership
    + data (optional, PersonResourceIdentifier)
+ fusebill_subscription - When present, this membership is managed by a subscription within fusebill.
    + data (optional)
        + type: `fusebill_subscriptions` (required, string)
        + id: `577dcfb9-8ffe-4517-b159-64f57c1a3343` (required, string) - Fusebill subscription uuid

## OrganizationMembershipResponseResource (OrganizationMembershipResourceIdentifier)

+ attributes (OrganizationMembershipAttributes)
+ relationships (OrganizationMembershipRelationships)
## RoleResourceIdentifier (object)
+ id: `4a361828-5ce4-4e2d-b271-d7a81af8fff7` (required) - Role's uuid
+ type: `roles` (required) - Resource Type

## RoleResourceWithOrgIdentifier (object)
+ id: `eb5bb3d7-bc71-48a5-bee3-7882c9694461` (required) - Role's uuid
+ type: `roles` (required) - Resource Type

## RoleAttributes (object)
+ name: `Membership Manager` (string)
+ description: `Membership Manager Role` (string)

## RoleWithOrgAttributes (object)
+ name: `Member` (string)
+ description: `Member Role` (string)

## RoleResponseAttributes (RoleAttributes)
+ uuid: `4a361828-5ce4-4e2d-b271-d7a81af8fff7`
+ slug: `membership_manager` (string)


## RoleResponseWithOrgAttributes (RoleWithOrgAttributes)
+ uuid: `eb5bb3d7-bc71-48a5-bee3-7882c9694461`
+ slug: `member` (string)

## RoleResponseResource (RoleResourceIdentifier)
+ attributes (RoleResponseAttributes)
+ relationships (object)
    + resource (object)
        + data: null
    + people (object)
        + meta (object)
            + total_count: `0` (number)


## RoleResponseResourceWithOrg (RoleResourceWithOrgIdentifier)
+ attributes (RoleResponseWithOrgAttributes)
+ relationships (object)
    + resource (object)
        + data (OrganizationResourceIdentifier)

    + people (object)
        + meta (object)
            + total_count: `2` (number)


## RoleRequestResource (object)
+ type: `roles` (string) - Type of resource. Must have a value of 'roles'
+ attributes (RoleAttributes)


## RolesIncludes (array)

### Items
+ (OrganizationResourceIdentifier)
## UserResourceIdentifier (object)
+ id: `718b6f8f-7865-442e-92af-c0f91b40dbb8` (required) - User's uuid
+ type: `users` (required) - Resource Type
## UserIdentityProviderResourceIdentifier (object)
+ id: `1c50172e-4943-49b7-b35e-98b170bcc9c7` (required) - UserIdentityProvider's uuid
+ type: `user_identities` (required) - Resource type

## UserIdentityResourceIdentifier (object)
+ id: `ea03ad13-ad3a-4058-88df-c365ed68c5fd` (required) - UserIdentity's uuid
+ type: `user_identities` (required) - Resource type

## UserIdentityAttributes (object)
+ provider_slug: `b2c` (string) - Slug for user identity provider configured in Wicket.
+ external_id: `30e165a2-4b5c-4dee-b8ff-cd7eac8f8f65` (string) - External ID of the user in external identity provider

## UserIdentityResponseAttributes (UserIdentityAttributes)`
+ provider_type: (enum) - Type of identity provider, Read Only
    - Sample: generic_oidc
+ provider_name: `B2C` (string) - Friendly name of the identity provider (Read only)
+ external_url: `https://example.com/users/30e165a2-4b5c-4dee-b8ff-cd7eac8f8f65` (string, optional) - When configured contains link to user in external identity provider (Read only)
+ created_at: `2017-07-27T22:21:26.824Z` - ISO 8601 of creation time
+ updated_at: `2017-07-27T22:47:45.129Z` - ISO 8601 of last modification

## UserIdentityResponseResource (UserIdentityResourceIdentifier)
+ attributes (UserIdentityResponseAttributes)
+ relationships (object)
    + person (object)
        + data (PersonResourceIdentifier)
    + user (object)
        + data (UserResourceIdentifier)
    + user_identity_provider (object)
        + data (UserIdentityProviderResourceIdentifier)

## UserIdentityRequestResource (object)
+ attributes (UserIdentityAttributes)
    + email: john@acme.ca (string) - Email address of user identify to provision
    + email_type: work (string) - Type to use for email, only applies to new email records
    + replace_primary_email: `true` (boolean, optional) - Ensures the provided email is marked as primary. This applies when a person is found by external id and the provided email does not exist in Wicket.
    + person (object)
        + given_name: John (string) - Given name of user to provision, only applies to new users.
        + family_name: Doe (string) - Family name of user to provision, only applies to new users.

+ relationships (object)
    + user_identity_provider (object, optional)
        + data (UserIdentityProviderResourceIdentifier)

## UserIdentityIncludes (array)

Allowed relationship includes are: user, user_identity_provider, person

### Items
## WidgetTokenCreateAttributes (object)

+ `widget_context`: `organizations` (enum) - Identifies the context this token is intended for, this field along with relationships is used to determine the necessary permissions.

## WidgetTokenCreateRelationships
+ subject - This is the subject of the token, the person who is being granted access.
    + data (PersonResourceIdentifier)
+ resource - This is the resource the subject of the token is granted access to modify via the widgets
    + data (OrganizationResourceIdentifier)



## WidgetTokenCreateRequest (object)
+ attributes (WidgetTokenCreateAttributes)
+ relationships (WidgetTokenCreateRelationships)

## WidgetTokenCreateResponse
+ token: `sample.token.here` - Wicket API access token for use with embed widgets
## WebhookEndpointResourceIdentifier (object)
+ id: `8f24a446-acac-40bb-8ff3-e6abc051c362` (required) - Webhook Endpoint's uuid
+ type: `endpoints` (required) - Resource Type

## WebhookEndpointAttributes (object)
+ name: `My Webhook` (string)
+ target_url: `https://trayapp.io` (string)
+ status: `active` (enum)
    - active
    - inactive
+ signature_key_masked: `****3346` (string) - Masked preview of webhook signing key
+ signature_key (string) - Webhook signing key, Only available on create or regenerate
+ events: (array) - You can subscribe to multiple `events` with a single `target_url`
    - (enum)
        - people.changed
        - people.address_changed
        - people.email_changed
        - people.phone_changed
        - people.web_address_changed
        - organizations.changed
        - organizations.address_changed
        - organizations.email_changed
        - organizations.phone_changed
        - organizations.web_address_changed
        - roles.granted
        - roles.revoked

## WebhookEndpointResource
+ attributes (WebhookEndpointAttributes)

## WebhookEndpointResponseAttributes (WebhookEndpointAttributes)
+ uuid: `8f24a446-acac-40bb-8ff3-e6abc051c362`
+ created_at: `2017-07-27T22:21:26.824Z` - ISO 8601 of creation time
+ updated_at: `2017-07-27T22:47:45.129Z` - ISO 8601 of last modification

## WebhookEndpointResponseResource (WebhookEndpointResourceIdentifier)
+ attributes (WebhookEndpointResponseAttributes)

## WebhookEndpointRequestResource (object)
+ type: `endpoints` (string) - Type of resource. Must have a value of 'endpoints'
+ attributes (WebhookEndpointAttributes)

## WebhookEndpointNavLinks (object)
+ first: `/webhook/endpoints?page%5Bnumber%5D=1&page%5Bsize%5D=12` - Link to first page
+ prev: `/webhook/endpoints?page%5Bnumber%5D=2&page%5Bsize%5D=12` - Link to previous page
+ self: `/webhook/endpoints?page%5Bnumber%5D=3&page%5Bsize%5D=12` - Link to current page
+ next: `/webhook/endpoints?page%5Bnumber%5D=4&page%5Bsize%5D=12` - Link to next page
+ last: `/webhook/endpoints?page%5Bnumber%5D=5&page%5Bsize%5D=12` - Link to last page

## PersonWebhookDefaultPayload (object)
+ webhook_delivery_timestamp: `2023-06-20T18:10:20.183Z` - ISO 8601 of creation time
+ webhook_bundle_uuid: `be14b026-a972-4a69-bdc2-b58ce8c2d2c0` - Webhook Event Bundle's uuid

## RelationshipsPersonData (object)
+ data (object)
    + id: `a5b33199-9dd3-4552-8c47-028f6c32ef48` - Person's uuid
    + type: `people`

## PeopleChangedEvent (object)
+ id: `a5b33199-9dd3-4552-8c47-028f6c32ef48` - Person's uuid
+ type: `person_webhook_summary`
+ `event_type`: `people.changed`
+ event_id: `6d2b7371-36b9-4060-b6ca-ddafda50541d` - Webhook Event's uuid
+ event_timestamp: `2023-06-21T16:53:43.604Z`
+ attributes (object)
    + uuid: `a5b33199-9dd3-4552-8c47-028f6c32ef48` - Person's uuid
    + given_name: `John` - Given name of the person (first)
    + family_name: `Smith` - Family name of the person (last)
    + primary_email_address: `john@mail.com` - Primary email address of the person
    + updated_at: `2023-06-21T16:53:43.600Z` - ISO 8601 of last modification

## PeopleAddressChangedEvent (object)
+ id: `78cd03e3-2b9b-4c5c-8d59-de5b80a8b820` - Address's uuid
+ type: `address_changed_webhook_summary`
+ `event_type`: `people.address_changed` (string)
+ event_id: `6d2b7371-36b9-4060-b6ca-ddafda50541d` - Webhook Event's uuid
+ event_timestamp: `2023-06-21T16:53:43.604Z`
+ attributes (object)
    + uuid: `a5b33199-9dd3-4552-8c47-028f6c32ef48` - Address's uuid
    + address1: `123 Wicket Lane` (string) - Address line 1
    + address2: `Top Floor` (string) - Address line 2
    + city: `Ottawa` (string) - The city
    + zip_code: `K1Z 6X6` (string) - The zip/postal code
    + state_name: `ON` (string) - State or province
    + country_code: `CA` (string) - Country code
    + primary: `true` (boolean) - Is the address flagged as primary
    + primary_changed: `true` (boolean) - Is the primary flag changed
    + updated_at: `2023-06-21T16:53:43.600Z` - ISO 8601 of last modification
+ relationships (object)
    + addressable (RelationshipsPersonData)

## PeoplePhoneChangedEvent (object)
+ id: `a5b33199-9dd3-4552-8c47-028f6c32ef48` - Phone's uuid
+ type: `phone_changed_webhook_summary`
+ `event_type`: `people.phone_changed`
+ event_id: `6d2b7371-36b9-4060-b6ca-ddafda50541d` - Webhook Event's uuid
+ event_timestamp: `2023-06-21T16:53:43.604Z`
+ attributes (object)
    + uuid: `a5b33199-9dd3-4552-8c47-028f6c32ef48` - Phone's uuid
    + number: `+16133556666` (string) - The phone number
    + number_was: `+16133556664` (string) - Previous value for phone number
    + primary: `true` (boolean) - Is the phone flagged as primary
    + primary_changed: `true` (boolean) - Is the primary flag changed
    + primary_was: `false` (boolean) - Previous value for the primary flag
    + updated_at: `2023-06-21T16:53:43.600Z` - ISO 8601 of last modification
+ relationships (object)
    + phoneable (RelationshipsPersonData)

## PeopleEmailChangedEvent (object)
+ id: `e7f180d7-9830-494c-8e5e-eb864dcf29dc` - Email's uuid
+ type: `email_changed_webhook_summary`
+ `event_type`: `people.email_changed`
+ event_id: `6d2b7371-36b9-4060-b6ca-ddafda50541d` - Webhook Event's uuid
+ event_timestamp: `2023-06-21T16:53:43.604Z`
+ attributes (object)
    + uuid: `e7f180d7-9830-494c-8e5e-eb864dcf29dc` - Email's uuid
    + address: `newjohn@mail.com` (string) - The full email address
    + address_was: `john@mail.com` (string) - Previous value for email address
    + primary: `true` (boolean) - Is the email flagged as primary
    + primary_changed: `true` (boolean) - Is the primary flag changed
    + primary_was: `false` (boolean) - Previous value for the primary flag
    + updated_at: `2023-06-21T16:53:43.600Z` - ISO 8601 of last modification
+ relationships (object)
    + emailable (RelationshipsPersonData)

## PeopleWebAddressChangedEvent (object)
+ id: `a76f60fc-f6a2-4415-8b38-2f5902fa9e70` - Web Address's uuid
+ type: `web_address_changed_webhook_summary`
+ `event_type`: `people.web_address_changed` (string)
+ event_id: `6d2b7371-36b9-4060-b6ca-ddafda50541d` - Webhook Event's uuid
+ event_timestamp: `2025-04-15T13:57:03.153Z`
+ attributes (object)
    + uuid: `a76f60fc-f6a2-4415-8b38-2f5902fa9e70` - Web Address's uuid
    + address: `https://www.example.com` (string) - Web Address valid url
    + updated_at: `2025-04-15T13:57:03.147Z` - ISO 8601 of last modification
+ relationships (object)
    + web_addressable (RelationshipsPersonData)
## OrganizationWebhookDefaultPayload (object)
+ webhook_delivery_timestamp: `2023-06-20T18:10:20.183Z` - ISO 8601 of creation time
+ webhook_bundle_uuid: `be14b026-a972-4a69-bdc2-b58ce8c2d2c0` - Webhook Event Bundle's uuid

## RelationshipsOrganizationData (object)
+ data (object)
    + id: `a5b33199-9dd3-4552-8c47-028f6c32ef48` - Organization's uuid
    + type: `organizations`

## OrganizationsChangedEvent (object)
+ id: `a5b33199-9dd3-4552-8c47-028f6c32ef48` - Organization's uuid
+ type: `organizations_webhook_summary`
+ `event_type`: `organizations.changed`
+ event_id: `6d2b7371-36b9-4060-b6ca-ddafda50541d` - Webhook Event's uuid
+ event_timestamp: `2023-06-21T16:53:43.604Z`
+ attributes (object)
    + uuid: `a5b33199-9dd3-4552-8c47-028f6c32ef48` - Organization's uuid
    + legal_name: `Acme University` (string) - The name of the organization
    + alternate_name: `UofA` (string) - The alternate name of the organization
    + type: `Education` (string) - The type of organization from a enumerable list
    + updated_at: `2023-06-21T16:53:43.600Z` - ISO 8601 of last modification

## OrganizationsAddressChangedEvent (object)
+ id: `78cd03e3-2b9b-4c5c-8d59-de5b80a8b820` - Address's uuid
+ type: `address_changed_webhook_summary`
+ `event_type`: `organizations.address_changed` (string)
+ event_id: `6d2b7371-36b9-4060-b6ca-ddafda50541d` - Webhook Event's uuid
+ event_timestamp: `2023-06-21T16:53:43.604Z`
+ attributes (object)
    + uuid: `a5b33199-9dd3-4552-8c47-028f6c32ef48` - Address's uuid
    + address1: `123 Wicket Lane` (string) - Address line 1
    + address2: `Top Floor` (string) - Address line 2
    + city: `Ottawa` (string) - The city
    + zip_code: `K1Z 6X6` (string) - The zip/postal code
    + state_name: `ON` (string) - State or province
    + country_code: `CA` (string) - Country code
    + primary: `true` (boolean) - Is the address flagged as primary
    + primary_changed: `true` (boolean) - Is the primary flag changed
    + updated_at: `2023-06-21T16:53:43.600Z` - ISO 8601 of last modification
+ relationships (object)
    + addressable (RelationshipsOrganizationData)

## OrganizationsPhoneChangedEvent (object)
+ id: `a5b33199-9dd3-4552-8c47-028f6c32ef48` - Phone's uuid
+ type: `phone_changed_webhook_summary`
+ `event_type`: `organizations.phone_changed`
+ event_id: `6d2b7371-36b9-4060-b6ca-ddafda50541d` - Webhook Event's uuid
+ event_timestamp: `2023-06-21T16:53:43.604Z`
+ attributes (object)
    + uuid: `a5b33199-9dd3-4552-8c47-028f6c32ef48` - Phone's uuid
    + number: `+16133556666` (string) - The phone number
    + number_was: `+16133556664` (string) - Previous value for phone number
    + primary: `true` (boolean) - Is the phone flagged as primary
    + primary_changed: `true` (boolean) - Is the primary flag changed
    + primary_was: `false` (boolean) - Previous value for the primary flag
    + updated_at: `2023-06-21T16:53:43.600Z` - ISO 8601 of last modification
+ relationships (object)
    + phoneable (RelationshipsOrganizationData)

## OrganizationsEmailChangedEvent (object)
+ id: `e7f180d7-9830-494c-8e5e-eb864dcf29dc` - Email's uuid
+ type: `email_changed_webhook_summary`
+ `event_type`: `organizations.email_changed`
+ event_id: `6d2b7371-36b9-4060-b6ca-ddafda50541d` - Webhook Event's uuid
+ event_timestamp: `2023-06-21T16:53:43.604Z`
+ attributes (object)
    + uuid: `e7f180d7-9830-494c-8e5e-eb864dcf29dc` - Email's uuid
    + address: `newjohn@mail.com` (string) - The full email address
    + address_was: `john@mail.com` (string) - Previous value for email address
    + primary: `true` (boolean) - Is the email flagged as primary
    + primary_changed: `true` (boolean) - Is the primary flag changed
    + primary_was: `false` (boolean) - Previous value for the primary flag
    + updated_at: `2023-06-21T16:53:43.600Z` - ISO 8601 of last modification
+ relationships (object)
    + emailable (RelationshipsOrganizationData)

## OrganizationsWebAddressChangedEvent (object)
+ id: `3633757a-b91f-4776-99c8-fb600da82545` - Web Address's uuid
+ type: `web_address_changed_webhook_summary`
+ `event_type`: `organizations.web_address_changed` (string)
+ event_id: `6d2b7371-36b9-4060-b6ca-ddafda50541d` - Webhook Event's uuid
+ event_timestamp: `2025-04-15T13:59:15.136Z`
+ attributes (object)
    + uuid: `3633757a-b91f-4776-99c8-fb600da82545` - Web Address's uuid
    + address: `https://www.example.com` (string) - Web Address valid url
    + updated_at: `2025-04-15T13:59:15.134Z` - ISO 8601 of last modification
+ relationships (object)
    + web_addressable (RelationshipsOrganizationData)
## RoleWebhookDefaultPayload (object)
+ webhook_delivery_timestamp: `2023-06-20T18:10:20.183Z` - ISO 8601 of creation time
+ webhook_bundle_uuid: `be14b026-a972-4a69-bdc2-b58ce8c2d2c0` - Webhook Event Bundle's uuid

## RolesAuditEvent (object)
+ event_id: `49854c85-ab86-4b28-9f32-c2fee1ddca4e`
+ event_timestamp: `2023-06-14T19:13:30.361Z`
+ role_uuid: `90d05aa8-0f1e-4f27-91e2-fd70c2a0213b`
+ role_name: `Hivebrite Approved`
+ entity_type: (enum, sample)
    - Person
    - Organization
+ entity_uuid: `d0ad4ea8-fd76-44fb-a6a5-56572eae3712`
+ role_resource_name: ``
+ role_resource_type: ``
+ role_resource_uuid: ``

## RolesGrantedEvent (RolesAuditEvent)
+ `event_type`: roles.granted

## RolesRevokedEvent (RolesAuditEvent)
+ `event_type`: roles.revoked


## Error (object)
Error objects provide additional information about errors
encountered while performing an operation.

## Error400 (object)
+ errors (array)
+ message: `Bad request`
+ status: 400 (number)

## Error401 (object)
+ errors (array)
+ message: `Not authenticated`
+ status: 401 (number)

## Error403 (object)
+ errors (array)
+ message: `Not authorized`
+ status: 403 (number)

## Error404 (object)
+ errors (array)
+ message: `Record not found`
+ status: 404 (number)

## Error409 (object)
+ errors (array)
+ message: `Record conflict`,
+ status: 409 (number)

## ErrorVersion409 (object)
+ errors (array)
+ message: `Record version conflict`,
+ status: 409 (number)

## Error422 (object)
+ errors (array)
+ message: `Invalid record`,
+ status: 422 (number)

## Error500 (object)
+ errors (array)
+ message: `Internal Server Error`,
+ status: 500 (number)
## PaginationMeta (object)
+ total_items: 1 (number)
+ total_pages: 1 (number)
+ number: 1 (number)
+ size: 25 (number)

## PeopleNavLinks (object)
+ first: `/people?page%5Bnumber%5D=1&page%5Bsize%5D=12` - Link to first page
+ prev: `/people?page%5Bnumber%5D=2&page%5Bsize%5D=12` - Link to previous page
+ self: `/people?page%5Bnumber%5D=3&page%5Bsize%5D=12` - Link to current page
+ next: `/people?page%5Bnumber%5D=4&page%5Bsize%5D=12` - Link to next page
+ last: `/people?page%5Bnumber%5D=5&page%5Bsize%5D=12` - Link to last page

## TouchpointsNavLinks (object)
+ first: `/touchpoints?page%5Bnumber%5D=1&page%5Bsize%5D=12` - Link to first page
+ prev: `/touchpoints?page%5Bnumber%5D=2&page%5Bsize%5D=12` - Link to previous page
+ self: `/touchpoints?page%5Bnumber%5D=3&page%5Bsize%5D=12` - Link to current page
+ next: `/touchpoints?page%5Bnumber%5D=4&page%5Bsize%5D=12` - Link to next page
+ last: `/touchpoints?page%5Bnumber%5D=5&page%5Bsize%5D=12` - Link to last page

## URL (string)
A resolvable [RFC3986] URL.

## Fragment (string)
A relative [RFC3986] URI fragment.

# Group Widgets

Wicket Widgets allow you to embed forms for modifying resource such as Person Profiles, Organization Profiles or Additional Info directly within your website, providing a similar UI to whats available in the Wicket Admin.

These widgets serve as building blocks for developing richer user experiences such as a member onboarding flows using Wordpress and WooCommerce.

For more details on the Javascript API's see the following:

- Production: [wicket-widgets-readme.html](https://wicket-core.s3.ca-central-1.amazonaws.com/wicket-widgets-readme.html)
- Staging: [wicket-widgets-readme-staging.html](https://wicket-core.s3.ca-central-1.amazonaws.com/wicket-widgets-readme-staging.html)

When making use of the organization widgets (editOrganizationProfile, editAdditionalInfo for organizations) an additional API call to [Create a Widget token](#reference/widgets/widgets-tokens/create-a-widget-token) must be executed server-side by the website administrator API client in order to retrieve a widget access token.

This token will grant a person temporary access to modify the organization details regardless of their roles, for this reason it is the responsibility of the website to perform any additional permission related checks based on the members details before granting this token.

## Widgets Tokens [/widget_tokens]

+ Attributes
    + attributes (WidgetTokenCreateAttributes)
    + relationships (WidgetTokenCreateRelationships)

### Create a Widget token [POST /widget_tokens]

Creates a widget token for use with the Wicket Widgets. The `token` returned in the response can be used with `accessToken` option when initializing the javascript widgets.

+ Request (application/json)
    + Attributes
        + data (WidgetTokenCreateRequest)

+ Response 200 (application/json)
    + Attributes (WidgetTokenCreateResponse)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

# Group Webhooks

Wicket sends Webhooks as a POST requests to the `target_url` when there are changes to entities in the system.

## Webhook Verification

For security purposes, each webhook request is signed with a signature key. This allows you to verify that the request genuinely came from our servers and not from a malicious third party.

### Signature Key

When creating a webhook, you'll receive a `signature_key` attribute in the response. **Important:** This key is only displayed once during webhook creation, so make sure to securely store it for future verification.

### Verifying Webhooks

Each request includes a `X-WicketEvents-Signature` header. To verify the webhook:

1. Extract the `X-WicketEvents-Signature` header from the incoming request
2. Use your stored signature key to generate an HMAC-SHA256 hash of the raw request body
3. Compare this hash with the value in the signature header

### Test Payload

When creating a new webhook endpoint, Wicket sends a test payload to verify the endpoint is working correctly. This payload has the following structure:

```json
{
  "webhook_delivery_timestamp": "2025-04-05T12:00:00Z",
  "webhook_bundle_uuid": null,
  "events": [],
  "test": true
}
```

Note that the test payload is also signed with the `signature_key`, but since this key is only returned after successful webhook creation, you won't be able to verify the signature of this initial test request. Your implementation should skip signature verification when receiving payloads with `"test": true`.

### Additional Headers

The request also includes an `X-WicketEvents-Host` header that identifies the originating host.


## Webhook Endpoints [/webhook/endpoints]

Create an Endpoint to receive webhook events from Wicket.

Endpoints can be created, fetched, updated and destroyed.

+ Attributes (WebhookEndpointAttributes)

### Fetch Webhook Endpoints [GET /webhook/endpoints?page[number]={page_number}&page[size]={page_size}]

+ Parameters
    + page_number (number, `1`) -  Page number to retrieve from pagination (optional query string param)
    + page_size (number, `12`) - Number if items per page to retrieve from pagination (optional query string param)

+ Response 200 (application/json)
    + Attributes
        + data (array)
            + (WebhookEndpointResponseResource)
        + links (WebhookEndpointNavLinks)
        + meta (object, required)
            + page (PaginationMeta)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)


### Fetch a Single Webhook Endpoint [GET /webhook/endpoints/{endpoint_id}]

+ Parameters
    + endpoint_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Webhook Endpoint's uuid

+ Response 200 (application/json)
    + Attributes
        + data (WebhookEndpointResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 500 (application/json)
    + Attributes (Error500)


### Create Webhook Endpoint [POST /webhook/endpoints]

+ Request (application/json)
    + Attributes
        + data (WebhookEndpointRequestResource)

+ Response 200 (application/json)
    + Attributes
        + data (WebhookEndpointResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)


### Update Webhook Endpoint [PATCH /webhook/endpoints/{endpoint_id}]

+ Parameters
    + endpoint_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Webhook Endpoint's uuid

+ Request (application/json)
    + Attributes
        + data (WebhookEndpointRequestResource)

+ Response 200 (application/json)
    + Attributes
        + data (WebhookEndpointResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)

### Regenerate Webhook Endpoint Signature [PATCH /webhook/endpoints/{endpoint_id}/regenerate_signature]

+ Parameters
    + endpoint_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Webhook Endpoint's uuid

+ Request (application/json)

+ Response 200 (application/json)
    + Attributes
        + data (WebhookEndpointResponseResource)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 422 (application/json)
    + Attributes (Error422)

+ Response 500 (application/json)
    + Attributes (Error500)


### Delete Webhook Endpoint [DELETE /webhook/endpoints/{endpoint_id}]

+ Parameters
    + endpoint_id (string, `8f24a446-acac-40bb-8ff3-e6abc051c362`) - Webhook Endpoint's uuid

+ Request (application/json)

+ Response 204 (application/json)

+ Response 401 (application/json)
    + Attributes (Error401)

+ Response 403 (application/json)
    + Attributes (Error403)

+ Response 404 (application/json)
    + Attributes (Error404)

+ Response 500 (application/json)
    + Attributes (Error500)

## People Event Payloads [/people_events_target_url]

### PeopleChanged Event Payload [POST]

+ Response 200 (application/json)
    + Attributes (PersonWebhookDefaultPayload)
        + events (array)
            + (PeopleChangedEvent)

### PeopleAddressChanged Event Payload [POST]

+ Response 200 (application/json)
    + Attributes (PersonWebhookDefaultPayload)
        + events (array)
            + (PeopleAddressChangedEvent)

### PeoplePhoneChanged Event Payload [POST]

+ Response 200 (application/json)
    + Attributes (PersonWebhookDefaultPayload)
        + events (array)
            + (PeoplePhoneChangedEvent)

### PeopleEmailChanged Event Payload [POST]

+ Response 200 (application/json)
    + Attributes (PersonWebhookDefaultPayload)
        + events (array)
            + (PeopleEmailChangedEvent)

### PeopleWebAddressChanged Event Payload [POST]

+ Response 200 (application/json)
    + Attributes (PersonWebhookDefaultPayload)
        + events (array)
            + (PeopleWebAddressChangedEvent)

## Organizations Event Payloads [/organizations_events_target_url]

### OrganizationsChanged Event Payload [POST]

+ Response 200 (application/json)
    + Attributes (OrganizationWebhookDefaultPayload)
        + events (array)
            + (OrganizationsChangedEvent)

### OrganizationsAddressChanged Event Payload [POST]

+ Response 200 (application/json)
    + Attributes (OrganizationWebhookDefaultPayload)
        + events (array)
            + (OrganizationsAddressChangedEvent)

### OrganizationsPhoneChanged Event Payload [POST]

+ Response 200 (application/json)
    + Attributes (OrganizationWebhookDefaultPayload)
        + events (array)
            + (OrganizationsPhoneChangedEvent)

### OrganizationsEmailChanged Event Payload [POST]

+ Response 200 (application/json)
    + Attributes (OrganizationWebhookDefaultPayload)
        + events (array)
            + (OrganizationsEmailChangedEvent)

### OrganizationsWebAddressChanged Event Payload [POST]

+ Response 200 (application/json)
    + Attributes (OrganizationWebhookDefaultPayload)
        + events (array)
            + (OrganizationsWebAddressChangedEvent)

## Roles Event Payloads [/roles_events_target_url]

### RolesGranted Event Payload [POST]

+ Response 200 (application/json)
    + Attributes (RoleWebhookDefaultPayload)
        + events (array)
            + (RolesGrantedEvent)

### RolesRevoked Event Payload [POST]

+ Response 200 (application/json)
    + Attributes (RoleWebhookDefaultPayload)
        + events (array)
            + (RolesRevokedEvent)
