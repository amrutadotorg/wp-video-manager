/**
 * JavaScript for Video Chapters Users Manager.
 * Handles autocomplete and add/remove logic for the allowed users list.
 */
jQuery(document).ready(function ($) {
    const $searchInput = $('#vcu-user-search');
    const $addButton = $('#vcu-add-user');
    const $usersTableBody = $('#vcu-users-table tbody');

    let selectedUserId = null;

    // Initialize autocomplete
    $searchInput.autocomplete({
        source: function (request, response) {
            $.ajax({
                url: videoChaptersUsers.ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'video_chapters_search_users',
                    nonce: videoChaptersUsers.nonce,
                    term: request.term,
                },
                success: function (res) {
                    if (res.success) {
                        response(res.data);
                    } else {
                        response([]);
                    }
                },
                error: function () {
                    response([]);
                },
            });
        },
        minLength: 2,
        select: function (event, ui) {
            selectedUserId = ui.item.id;
            $searchInput.val(ui.item.label);
            $addButton.prop('disabled', false);
            return false;
        },
        search: function () {
            selectedUserId = null;
            $addButton.prop('disabled', true);
        },
    });

    // Handle Add User
    $addButton.on('click', function () {
        if (!selectedUserId) {
            return;
        }

        const button = $(this);
        button.prop('disabled', true).text('Adding...');

        $.ajax({
            url: videoChaptersUsers.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'video_chapters_save_allowed_users',
                nonce: videoChaptersUsers.nonce,
                user_action: 'add',
                user_id: selectedUserId,
            },
            success: function (res) {
                if (res.success) {
                    const user = res.data;
                    
                    // Remove empty row if exists
                    $usersTableBody.find('.vcu-empty-row').remove();

                    // Check if already in table to prevent duplicates visually
                    if ($usersTableBody.find('tr[data-user-id="' + user.user_id + '"]').length === 0) {
                        const $newRow = $('<tr>', { 'data-user-id': user.user_id }).append(
                            $('<td>').text(`${user.display_name} (${user.user_login})`),
                            $('<td>').text(user.user_email),
                            $('<td>').append(
                                $('<button>', {
                                    type: 'button',
                                    class: 'button vcu-remove-user',
                                    'data-user-id': user.user_id,
                                }).text('Remove')
                            )
                        );
                        $usersTableBody.append($newRow);
                    }
                    
                    // Reset search
                    $searchInput.val('');
                    selectedUserId = null;
                    $addButton.prop('disabled', true);
                } else {
                    alert('Error adding: ' + res.data);
                    $addButton.prop('disabled', false);
                }
            },
            error: function () {
                alert('Network error.');
                $addButton.prop('disabled', false);
            },
            complete: function () {
                button.text('Add');
            },
        });
    });

    // Handle Remove User
    $usersTableBody.on('click', '.vcu-remove-user', function () {
        const button = $(this);
        const userId = button.data('user-id');
        const row = button.closest('tr');

        if (!confirm('Are you sure you want to remove this user from the list?')) {
            return;
        }

        button.prop('disabled', true).text('Removing...');

        $.ajax({
            url: videoChaptersUsers.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'video_chapters_save_allowed_users',
                nonce: videoChaptersUsers.nonce,
                user_action: 'remove',
                user_id: userId,
            },
            success: function (res) {
                if (res.success) {
                    row.remove();
                    if ($usersTableBody.find('tr').length === 0) {
                        $usersTableBody.append(`
                            <tr class="vcu-empty-row">
                                <td colspan="3">No additional users on the list.</td>
                            </tr>
                        `);
                    }
                } else {
                    alert('Error removing: ' + res.data);
                    button.prop('disabled', false).text('Remove');
                }
            },
            error: function () {
                alert('Network error.');
                button.prop('disabled', false).text('Remove');
            }
        });
    });
});
