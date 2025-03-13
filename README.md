# YOURLS Random Redirect Manager

A plugin for YOURLS that redirects specific keywords to randomly selected URLs from predefined lists with customizable probability weights.

Listed in [![Listed in Awesome YOURLS!](https://img.shields.io/badge/Awesome-YOURLS-C5A3BE)](https://github.com/YOURLS/awesome-yourls/)

## Features

- Create multiple redirect lists triggered by different keywords
- Set custom probability percentages for each destination URL
- Automatic shortlink creation for configured keywords
- User-friendly admin interface with percentage calculation
- Enable/disable redirect lists as needed

## Installation

1. Download the plugin
2. Place the plugin folder in your `user/plugins` directory
3. Activate the plugin in the YOURLS admin interface

## Usage

1. Go to "Random Redirect Manager" in your YOURLS admin panel
2. Create redirect lists by specifying:
   - A keyword that triggers the redirect
   - Multiple destination URLs
   - Optional percentage chances for each URL
3. Save your settings

When users visit your shortlink with the specified keyword, they'll be randomly redirected to one of the destination URLs based on your configured probabilities.

## Example Use Cases

- A/B testing different landing pages
- Traffic distribution among multiple affiliate offers
- Load balancing between different servers
- Creating a "surprise" link that goes to different content each time
