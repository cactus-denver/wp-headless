# WP Headless
A simple plugin for publishing static JSON files from wordpress for a headless CMS.

## Cactus Fork
- Added a new settings parameter "host" which is prepended to the uri's of the sitemap. This should eventually be in the dashboard settings instead of hardcoded. Currently it's hardcoded to https://cactusinc.com.
- Added "URI" to the parsePost function to return each piece of content's uri.
- Added a new array sitemap_items outside the content loop in publish(). This collects all the uri's from each content group.
- sitemap_items is then passed to a new function generateSitemap(). This function adds all the post type content since we aren't including them in our json and then it generates/uploads an xml file the same way the json object is uploaded.
- Had to change the uploader.php function to also post application/xml content. Default stays application/json.
