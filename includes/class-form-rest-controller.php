<?php
namespace TrinityForms;

defined( "ABSPATH" ) || exit;

/**
 * The core REST controller for forms (and Pro's Views), readable only by people
 * who manage the plugin.
 *
 * The post types are in REST because the block editor needs them. WordPress then
 * treats them like posts: anything published is readable by anyone, logged in or
 * not. For a form that is its whole definition (every field, hidden defaults, quiz
 * answers, prices; for a form stored as JSON also who gets the notifications),
 * including forms that are on no page.
 *
 * People who write posts but do not manage the plugin (Editors, Authors,
 * Contributors) may list published ones as id + title, for the block pickers.
 *
 * Writes need no extra rule: they follow the post type's capabilities, which all
 * map to Admin::CAP. Visitors never use this route; a form is drawn by PHP.
 */
class Form_REST_Controller extends \WP_REST_Posts_Controller {

    /** @return true|\WP_Error */
    private function managers_only() {
        if ( current_user_can( Admin::CAP ) ) {
            return true;
        }
        return new \WP_Error(
            "rest_forbidden",
            __( "Sorry, you are not allowed to do that.", "trinity-forms" ),
            [ "status" => rest_authorization_required_code() ]
        );
    }

    /**
     * Someone who writes posts but does not manage the plugin: they may place a
     * published form (or View) with the block, so they get its id and title,
     * nothing else (QA 7 Nov: the Editor's block picker was empty).
     */
    private function is_picker_user(): bool {
        return ! current_user_can( Admin::CAP ) && current_user_can( "edit_posts" );
    }

    public function get_items_permissions_check( $request ) {
        if ( $this->is_picker_user() ) {
            return true;
        }
        $allowed = $this->managers_only();
        return true === $allowed ? parent::get_items_permissions_check( $request ) : $allowed;
    }

    public function get_item_permissions_check( $request ) {
        if ( $this->is_picker_user() ) {
            $post = get_post( (int) $request["id"] );
            if ( $post instanceof \WP_Post && $post->post_type === $this->post_type && "publish" === $post->post_status ) {
                return true;
            }
        }
        $allowed = $this->managers_only();
        return true === $allowed ? parent::get_item_permissions_check( $request ) : $allowed;
    }

    /**
     * Core refuses status=draft (the picker asks for publish + draft) from anyone
     * who cannot edit the type, with a 400 before get_items() runs. A picker user
     * is only ever shown published ones, so read the status as "publish".
     */
    public function sanitize_post_statuses( $statuses, $request, $parameter ) {
        if ( $this->is_picker_user() ) {
            return [ "publish" ];
        }
        return parent::sanitize_post_statuses( $statuses, $request, $parameter );
    }

    public function get_items( $request ) {
        if ( ! $this->is_picker_user() ) {
            return parent::get_items( $request );
        }
        $ids = get_posts( [
            "post_type"        => $this->post_type,
            "post_status"      => "publish",
            "numberposts"      => 500,
            "orderby"          => "title",
            "order"            => "ASC",
            "fields"           => "ids",
            "suppress_filters" => true,
        ] );
        $response = rest_ensure_response( array_map( [ $this, "picker_item" ], array_map( "intval", $ids ) ) );
        $response->header( "X-WP-Total", (string) count( $ids ) );
        $response->header( "X-WP-TotalPages", "1" );
        return $response;
    }

    public function get_item( $request ) {
        if ( ! $this->is_picker_user() ) {
            return parent::get_item( $request );
        }
        return rest_ensure_response( $this->picker_item( (int) $request["id"] ) );
    }

    /** Id and title only. @return array<string,mixed> */
    private function picker_item( int $id ): array {
        $post = get_post( $id );
        return [
            "id"     => $id,
            "type"   => $this->post_type,
            "status" => "publish",
            "title"  => [
                "raw"      => $post ? (string) $post->post_title : "",
                "rendered" => get_the_title( $id ),
            ],
        ];
    }
}
