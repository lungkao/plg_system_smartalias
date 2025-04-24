# Smart Alias Plugin for Joomla

## Version: 1.1.0 (April 24, 2025)

A Joomla plugin for automatically generating SEO-friendly aliases for articles.

## Features

- Automatically generate URL aliases from article titles
- Limit alias length to a configurable maximum
- Option to append article ID for uniqueness
- Option to use only ID as alias
- Character counter for title and alias fields
- Clear alias button for easy regeneration
- Support for Joomla Core Articles and FlexiContent items
- Support for both English and Thai languages

## Compatibility

- Joomla 4.0+
- Joomla 5.0+
- FlexiContent 3.x+

## Installation

1. Download the latest release
2. Install via Joomla Extension Manager
3. Enable the plugin via Plugin Manager

## Languages

The plugin includes full language support for:
- English (en-GB)
- Thai (th-TH)

Both administrative interface and installation texts are localized.

## Configuration

### Basic Options

- **Maximum Alias Length**: Set the maximum number of characters for the alias. Leave blank or set to 0 for no limit.
- **Append ID to Alias**: Choose whether to append the article ID to the alias if it is not unique.
- **Use ID Only as Alias**: Choose whether to use only the article ID as the alias.
- **ID Suffix**: Add a prefix text before the ID when using ID only. Leave blank for no prefix.
- **Show Character Counter**: Enable or disable the character counter by default.

### Usage

When editing an article:
- The plugin automatically generates an alias from the title if no alias exists
- Character counters show the length of both title and alias
- Click the eye icon to toggle character counter visibility
- Click the "Clear Alias" button to clear the existing alias and generate a new one on save

## FlexiContent Support

This plugin supports FlexiContent, providing the same functionality for FlexiContent items as for Joomla core articles:
- Automatic alias generation
- Length limitation
- Character counters
- Clear alias functionality

## Credits

- **Developer**: Pisan Chueachatchai
- **Company**: Colorpack Creations Co.,Ltd.
- **Website**: https://colorpack.co.th/
- **Support**: If you find this plugin helpful, you can support the developer at: https://buymeacoffee.com/cheuachatchai

## License

GNU General Public License version 2 or later
Copyright (C) 2025 Colorpack Creations Co.,Ltd. All rights reserved.
