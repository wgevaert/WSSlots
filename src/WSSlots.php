<?php

namespace WSSlots;

use CommentStoreComment;
use Content;
use ContentHandler;
use MediaWiki\MediaWikiServices;
use MediaWiki\Storage\SlotRecord;
use MWException;
use RequestContext;
use SMW\ParserData;
use SMW\SemanticData;
use SMW\Store;
use TextContent;
use User;
use WikiPage;

/**
 * Class WSSlots
 *
 * This class contains static methods that may be used by WSSlots or other extensions for manipulating
 * slots.
 *
 * @package WSSlots
 */
abstract class WSSlots {
	/**
	 * @param User $user The user that performs the edit
	 * @param WikiPage $wikipage_object The page to edit
	 * @param string $text The text to insert/append
	 * @param string $slot_name The slot to edit
	 * @param string $summary The summary to use
	 * @param bool $append Whether to append to or replace the current text
     * @param string $watchlist Set to "nochange" to suppress watchlist notifications
	 *
	 * @return true|array True on success, and an error message with an error code otherwise
	 *
	 * @throws \MWContentSerializationException Should not happen
	 * @throws MWException Should not happen
	 */
	public static function editSlot(
		User $user,
		WikiPage $wikipage_object,
		string $text,
		string $slot_name,
		string $summary,
		bool $append = false,
        string $watchlist = ""
	) {
		$logger = Logger::getLogger();

		$title_object = $wikipage_object->getTitle();
		$page_updater = $wikipage_object->newPageUpdater( $user );
		$old_revision_record = $wikipage_object->getRevisionRecord();
		$slot_role_registry = MediaWikiServices::getInstance()->getSlotRoleRegistry();

		if ($title_object === null) {
			$logger->alert('The WikiPage object given to editSlot is not valid, since it does not contain a Title');
			return [wfMessage( "wsslots-error-invalid-wikipage-object")];
		}

		$logger->debug('Editing slot {slotName} on page {page}', [
			'slotName' => $slot_name,
			'page' => $title_object->getFullText()
		]);

		// Make sure the slot we are editing exists
		if ( !$slot_role_registry->isDefinedRole( $slot_name ) ) {
			$logger->alert('Tried to edit non-existent slot {slotName} on page {page}', [
				'slotName' => $slot_name,
				'page' => $title_object->getFullText()
			]);

			return [wfMessage( "wsslots-apierror-unknownslot", $slot_name ), "unknownslot"];
		}

		// Alter $text when the $append parameter is set
		if ( $append ) {
			// We want to append the given text to the current page, instead of replacing the content
			$content = self::getSlotContent( $wikipage_object, $slot_name );

			if ( $content !== null ) {
				if ( !( $content instanceof TextContent ) ) {
					$slot_content_handler = $content->getContentHandler();
					$model_id = $slot_content_handler->getModelID();

					$logger->alert('Tried to append to slot {slotName} with non-textual content model {modelId} while editing page {page}', [
						'slotName' => $slot_name,
						'modelId' => $model_id,
						'page' => $title_object->getFullText()
					]);

					return [wfMessage( "apierror-appendnotsupported" ), $model_id];
				}

				/** @var string $text */
				$content_text = $content->serialize();
				$text = $content_text . $text;
			}
		}

		if ( $text === "" && $slot_name !== SlotRecord::MAIN ) {
			// Remove the slot if $text is empty and the slot name is not MAIN
			$logger->debug('Removing slot {slotName} since it is empty', [
				'slotName' => $slot_name
			]);

			$page_updater->removeSlot( $slot_name );
		} else {
			// Set the content for the slot we want to edit
			if ( $old_revision_record !== null && $old_revision_record->hasSlot( $slot_name ) ) {
				$model_id = $old_revision_record
					->getSlot( $slot_name )
					->getContent()
					->getContentHandler()
					->getModelID();
			} else {
				$model_id = $slot_role_registry
					->getRoleHandler( $slot_name )
					->getDefaultModel( $title_object );
			}

			$logger->debug('Setting content in PageUpdater');

			$slot_content = ContentHandler::makeContent( $text, $title_object, $model_id );
			$page_updater->setContent( $slot_name, $slot_content );
		}

		if ( $old_revision_record === null && $slot_name !== SlotRecord::MAIN ) {
			// The 'main' content slot MUST be set when creating a new page
			$logger->debug('Setting empty "main" slot');

			$main_content = ContentHandler::makeContent("", $title_object);
			$page_updater->setContent( SlotRecord::MAIN, $main_content );
		}

		if ( $slot_name !== SlotRecord::MAIN ) {
			$page_updater->addTag( 'wsslots-slot-edit' );
		}

        $flags = EDIT_INTERNAL;
		$comment = CommentStoreComment::newUnsavedComment( $summary );

        if ( $watchlist === "nochange" ) {
            $flags |= EDIT_SUPPRESS_RC;
        }

		$logger->debug('Calling saveRevision on PageUpdater');
		$page_updater->saveRevision( $comment, $flags );
		$logger->debug('Finished calling saveRevision on PageUpdater');

		if ( !$page_updater->isUnchanged() ) {
			$logger->debug('Refreshing data for page {page}', [
				'page' => $title_object->getFullText()
			]);
			self::refreshData( $wikipage_object, $user );
		}

		return true;
	}

	/**
	 * @param WikiPage $wikipage
	 * @param string $slot
	 * @return Content|null The content in the given slot, or NULL if no content exists
	 */
	public static function getSlotContent( WikiPage $wikipage, string $slot ) {
		$revision_record = $wikipage->getRevisionRecord();

		if ( $revision_record === null ) {
			return null;
		}

		if ( !$revision_record->hasSlot( $slot ) ) {
			return null;
		}

		return $revision_record->getContent( $slot );
	}

    /**
     * Hook to extend the SemanticData object before the update is completed.
     *
     * @link https://github.com/SemanticMediaWiki/SemanticMediaWiki/blob/master/docs/technical/hooks/hook.store.beforedataupdatecomplete.md
     *
     * @param Store $store
     * @param SemanticData $semanticData
     * @return bool
     */
	public static function onBeforeDataUpdateComplete( Store $store, SemanticData $semanticData ): bool {
        $subjectTitle = $semanticData->getSubject()->getTitle();

        if ( $subjectTitle === null ) {
            return true;
        }

        $semanticSlots = RequestContext::getMain()->getConfig()->get('WSSlotsSemanticSlots');

        try {
            $wikiPage = WikiPage::factory( $subjectTitle );
        } catch ( MWException $exception ) {
            return true;
        }

        $revision = $wikiPage->getRevisionRecord();

        foreach ( $semanticSlots as $slot ) {
            if ( !$revision->hasSlot( $slot ) ) {
                continue;
            }

            $content = $revision->getContent( $slot );

            if ( $content === null ) {
                continue;
            }

            $parserOutput = $content->getParserOutput( $subjectTitle, $revision->getId() );
            $slotSemanticData = $parserOutput->getExtensionData( ParserData::DATA_ID );

            if ( $slotSemanticData === null ) {
                continue;
            }

            // Remove any pre-defined properties that exist in both the main semantic data as well as the slot semantic
            // data from the main semantic data to prevent them from merging
            foreach ( $slotSemanticData->getProperties() as $property ) {
                if ( !$property->isUserDefined() ) {
                    $semanticData->removeProperty( $property );
                }
            }

            $semanticData->importDataFrom( $slotSemanticData );
        }

        return true;
    }

	/**
	 * Performs a refresh if necessary.
	 *
	 * @param WikiPage $wikipage_object
	 * @param User $user
	 * @throws MWException
	 */
	private static function refreshData( WikiPage $wikipage_object, User $user ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();

		if ( !$config->get( "WSSlotsDoPurge" ) ) {
			return;
		}

		// Perform an additional null-edit to make sure all page properties are up-to-date
		$comment = CommentStoreComment::newUnsavedComment( "");
		$page_updater = $wikipage_object->newPageUpdater( $user );
		$page_updater->saveRevision( $comment, EDIT_SUPPRESS_RC | EDIT_AUTOSUMMARY );
	}
}
