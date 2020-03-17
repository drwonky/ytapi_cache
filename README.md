== Youtube GAPI v3 cache ==

This is a simple caching proxy that allows you to transparently query the
YouTube v3 API.

The YT API quotas are quite strict and even modest querying will quickly exhaust
the 10,000 unit allotment.

This is a PHP proxy that behaves just like the real youtube API endpoint, so
minor changes to your code are required to specify the API endpoint
(channels,search,etc).

The cached data is stored in a MySQL database, the default database name is
ytapi_cache.

The api_cache table has a column defined for the endpoint, but uses virtual
columns representing the JSON data so that a secondary index can be created on
(endpoint,part,apikey), this is used as a tuple to narrow down the row searches
in case there is a lot of data.  The contents of the json query are used as the
remaining key matches.  This approach creates a query pattern where a large
result set (the entire table) is narrowed down by a range scan, then a where can
be used to extract the specific records.

When using the YT API, the JS programmer creates a JSON document that is used as
the query, then a JSON document is returned by the API.  This proxy attempts to
use the same model, storing a JSON query in the database and the JSON result.

When a query is successful, the JSON result document from the API is sent back
to the client, it's treated as an opaque string and unmodified as returned from
the YT API.

The proxy does not use your API key, what it does is repeat what you send to the
API, so when you send your API key it will repeat that API key.  The consequence
of this is that the cache table contains your API key.  It is recommended that
you use restricted API keys and set the $api_referer value in the
credentials.php file.

Installation:

1. Copy the ytapi_cache.php file to someplace in your document tree where it's
   accessible via the web server
2. Copy the credentials_example.php file to credentials.php and edit the file,
   setting the appropriate defaults
3. Modify your API URL as such:

Original:

var url="https://www.googleapis.com/youtube/v3/search?";

Modified:

var url="https://example.com/path/to/ytapi_cache.php?endpoint=search&";

Note the endpoint for the API service is /search, you set the endpoint name in
the URL, then the rest of your query parameters are appended like usual.

You may optionally specify a cache age parameter for each API call, by appending
age=<INTEGER> to the query, you can set the cache lifetime for each API call.

Example:

var url="https://example.com/path/to/ytapi_cache.php?endpoint=search&age=900";

The default age is 3600 seconds.

4. Load your page, the ytapi_cache.php script will check if the api_cache table
   exists and create it if not.  It will then perform API lookups on your
   behalf.

