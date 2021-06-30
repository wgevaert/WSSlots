# WSSlots

This extension provides a mechanism to create new slots.

## Configuration

The extension provides the following configuration options:

### `$wgWSSlotsDefinedSlots`

This is an array of the slots to define. Each item in the array corresponds to the name of the slot to define. It is also possible to optionally configure the slot's content model and slot role layout, like so:

```php
$wgWSSlotsDefinedSlots = [
    "example" => [
        "content_model" => "wikitext",
        "slot_role_layout" => [
            "display" => "none",
            "region" => "center",
            "placement" => "append"
        ]
    ]
];
```

For more information on content models see [MediaWiki.org](https://www.mediawiki.org/wiki/Manual:Page_content_models) and for more information on slot role layouts see [here](https://doc.wikimedia.org/mediawiki-core/master/php/classMediaWiki_1_1Revision_1_1SlotRoleHandler.html#a42a50a9312fd931793c3573808f5b8a1).

### `$wgWSSlotsDefaultContentModel`

This is the default content model to use, if no content model is given explicitly.

### `$wgWSSlotsDefaultSlotRoleLayout`

This is the default slot role layout to use, if no slot role layout is given explicitly.