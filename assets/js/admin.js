/**
 * WP Puller Plugin Admin JavaScript
 *
 * @package WP_Puller_Plugin
 * @since 1.0.0
 */

(function($) {
    'use strict';

    var WPPPlugin = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $('#wp-puller-settings-form').on('submit', this.saveSettings.bind(this));
            $('#wp-puller-test-connection').on('click', this.testConnection.bind(this));
            $('#wp-puller-check-updates').on('click', this.checkUpdates.bind(this));
            $('#wp-puller-update-now').on('click', this.updateNow.bind(this));
            $('#wp-puller-regenerate-secret').on('click', this.regenerateSecret.bind(this));
            $('#wp-puller-clear-logs').on('click', this.clearLogs.bind(this));

            $(document).on('click', '.wp-puller-restore-backup', this.restoreBackup.bind(this));
            $(document).on('click', '.wp-puller-delete-backup', this.deleteBackup.bind(this));
            $(document).on('click', '.wp-puller-copy-btn', this.copyToClipboard.bind(this));
        },

        saveSettings: function(e) {
            e.preventDefault();

            var $form = $(e.currentTarget);
            var $btn = $form.find('[type="submit"]');

            this.setLoading($btn, true);

            $.ajax({
                url: wppPlugin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpp_plugin_save_settings',
                    nonce: wppPlugin.nonce,
                    repo_url: $('#wp-puller-repo-url').val(),
                    branch: $('#wp-puller-branch').val(),
                    plugin_slug: $('#wp-puller-plugin-slug').val(),
                    plugin_subdir: $('#wp-puller-plugin-subdir').val(),
                    pat: $('#wp-puller-pat').val(),
                    auto_update: $('#wp-puller-auto-update').is(':checked') ? 'true' : 'false',
                    backup_count: $('#wp-puller-backup-count').val()
                },
                success: function(response) {
                    if (response.success) {
                        WPPPlugin.showNotice(response.data.message, 'success');
                    } else {
                        WPPPlugin.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPPPlugin.showNotice(wppPlugin.strings.error, 'error');
                },
                complete: function() {
                    WPPPlugin.setLoading($btn, false);
                }
            });
        },

        testConnection: function(e) {
            var $btn = $(e.currentTarget);
            var repoUrl = $('#wp-puller-repo-url').val();

            if (!repoUrl) {
                this.showNotice('Please enter a repository URL.', 'error');
                return;
            }

            this.setLoading($btn, true);

            $.ajax({
                url: wppPlugin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpp_plugin_test_connection',
                    nonce: wppPlugin.nonce,
                    repo_url: repoUrl
                },
                success: function(response) {
                    if (response.success) {
                        var msg = wppPlugin.strings.connected;
                        if (response.data.repo) {
                            msg += ' Repository: ' + response.data.repo.full_name;
                            if (response.data.repo.private) {
                                msg += ' (Private)';
                            }
                            if (response.data.repo.default_branch && !$('#wp-puller-branch').val()) {
                                $('#wp-puller-branch').val(response.data.repo.default_branch);
                            }
                        }
                        WPPPlugin.showNotice(msg, 'success');
                    } else {
                        WPPPlugin.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPPPlugin.showNotice(wppPlugin.strings.error, 'error');
                },
                complete: function() {
                    WPPPlugin.setLoading($btn, false);
                }
            });
        },

        checkUpdates: function(e) {
            var $btn = $(e.currentTarget);
            var $result = $('#wp-puller-update-result');

            this.setLoading($btn, true);
            $result.hide();

            $.ajax({
                url: wppPlugin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpp_plugin_check_updates',
                    nonce: wppPlugin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        var html = '';

                        if (data.is_new_setup) {
                            html = '<p><strong>Ready to install.</strong> Click "Update Now" to pull the plugin from GitHub.</p>';
                            $result.removeClass('has-update no-update').addClass('has-update');
                        } else if (data.update_available) {
                            html = '<p><strong>Update available!</strong></p>';
                            html += '<p>Current: <code>' + data.current_commit.substring(0, 7) + '</code></p>';
                            html += '<p>Latest: <code>' + data.latest_commit.short_sha + '</code>';
                            if (data.latest_commit.message) {
                                html += ' - ' + WPPPlugin.escapeHtml(data.latest_commit.message.substring(0, 60));
                            }
                            html += '</p>';
                            $result.removeClass('has-update no-update').addClass('has-update');
                        } else {
                            html = '<p><strong>Plugin is up to date.</strong></p>';
                            html += '<p>Current commit: <code>' + data.latest_commit.short_sha + '</code></p>';
                            $result.removeClass('has-update no-update').addClass('no-update');
                        }

                        $result.html(html).show();
                        $('#last-check').text('just now');
                    } else {
                        WPPPlugin.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPPPlugin.showNotice(wppPlugin.strings.error, 'error');
                },
                complete: function() {
                    WPPPlugin.setLoading($btn, false);
                }
            });
        },

        updateNow: function(e) {
            var $btn = $(e.currentTarget);

            this.setLoading($btn, true);

            $.ajax({
                url: wppPlugin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpp_plugin_update_now',
                    nonce: wppPlugin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WPPPlugin.showNotice(wppPlugin.strings.updated, 'success');

                        if (response.data.status) {
                            $('#current-commit').text(response.data.status.short_commit || '-');
                        }

                        $('#wp-puller-update-result').hide();

                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        WPPPlugin.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPPPlugin.showNotice(wppPlugin.strings.error, 'error');
                },
                complete: function() {
                    WPPPlugin.setLoading($btn, false);
                }
            });
        },

        restoreBackup: function(e) {
            var $btn = $(e.currentTarget);
            var backupName = $btn.data('name');

            if (!confirm(wppPlugin.strings.confirmRestore)) {
                return;
            }

            this.setLoading($btn, true);

            $.ajax({
                url: wppPlugin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpp_plugin_restore_backup',
                    nonce: wppPlugin.nonce,
                    backup_name: backupName
                },
                success: function(response) {
                    if (response.success) {
                        WPPPlugin.showNotice(wppPlugin.strings.restored, 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        WPPPlugin.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPPPlugin.showNotice(wppPlugin.strings.error, 'error');
                },
                complete: function() {
                    WPPPlugin.setLoading($btn, false);
                }
            });
        },

        deleteBackup: function(e) {
            var $btn = $(e.currentTarget);
            var backupName = $btn.data('name');

            if (!confirm(wppPlugin.strings.confirmDelete)) {
                return;
            }

            this.setLoading($btn, true);

            $.ajax({
                url: wppPlugin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpp_plugin_delete_backup',
                    nonce: wppPlugin.nonce,
                    backup_name: backupName
                },
                success: function(response) {
                    if (response.success) {
                        $btn.closest('.wp-puller-backup-item').fadeOut(function() {
                            $(this).remove();

                            if ($('#wp-puller-backup-list li').length === 0) {
                                $('#wp-puller-backup-list').replaceWith(
                                    '<p class="wp-puller-empty">No backups yet. A backup is created automatically before each update.</p>'
                                );
                            }
                        });
                        WPPPlugin.showNotice(wppPlugin.strings.deleted, 'success');
                    } else {
                        WPPPlugin.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPPPlugin.showNotice(wppPlugin.strings.error, 'error');
                },
                complete: function() {
                    WPPPlugin.setLoading($btn, false);
                }
            });
        },

        regenerateSecret: function(e) {
            var $btn = $(e.currentTarget);

            if (!confirm(wppPlugin.strings.confirmRegenerate)) {
                return;
            }

            this.setLoading($btn, true);

            $.ajax({
                url: wppPlugin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpp_plugin_regenerate_secret',
                    nonce: wppPlugin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#webhook-secret').val(response.data.secret);
                        WPPPlugin.showNotice(wppPlugin.strings.regenerated, 'success');
                    } else {
                        WPPPlugin.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPPPlugin.showNotice(wppPlugin.strings.error, 'error');
                },
                complete: function() {
                    WPPPlugin.setLoading($btn, false);
                }
            });
        },

        clearLogs: function(e) {
            var $btn = $(e.currentTarget);

            this.setLoading($btn, true);

            $.ajax({
                url: wppPlugin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpp_plugin_clear_logs',
                    nonce: wppPlugin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#wp-puller-log-list').replaceWith(
                            '<p class="wp-puller-empty">No activity recorded yet.</p>'
                        );
                        $btn.remove();
                    } else {
                        WPPPlugin.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    WPPPlugin.showNotice(wppPlugin.strings.error, 'error');
                },
                complete: function() {
                    WPPPlugin.setLoading($btn, false);
                }
            });
        },

        copyToClipboard: function(e) {
            var $btn = $(e.currentTarget);
            var inputId = $btn.data('copy');
            var $input = $('#' + inputId);
            var text = $input.val();

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function() {
                    WPPPlugin.setCopiedState($btn);
                }).catch(function() {
                    WPPPlugin.copyFallback($input, $btn);
                });
            } else {
                WPPPlugin.copyFallback($input, $btn);
            }
        },

        copyFallback: function($input, $btn) {
            $input.select();
            try {
                document.execCommand('copy');
                WPPPlugin.setCopiedState($btn);
            } catch (err) {
                window.prompt('Copy to clipboard:', $input.val());
            }
        },

        setCopiedState: function($btn) {
            $btn.find('.dashicons').removeClass('dashicons-clipboard').addClass('dashicons-yes');
            setTimeout(function() {
                $btn.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-clipboard');
            }, 1500);
        },

        setLoading: function($btn, loading) {
            if (loading) {
                $btn.addClass('wp-puller-btn-loading').prop('disabled', true);
            } else {
                $btn.removeClass('wp-puller-btn-loading').prop('disabled', false);
            }
        },

        showNotice: function(message, type) {
            var $notice = $('#wp-puller-notice');
            var className = 'notice-' + (type || 'info');

            $notice
                .removeClass('notice-success notice-error notice-info')
                .addClass(className)
                .html(this.escapeHtml(message))
                .fadeIn();

            setTimeout(function() {
                $notice.fadeOut();
            }, 5000);

            $('html, body').animate({
                scrollTop: $('.wp-puller-wrap').offset().top - 50
            }, 300);
        },

        escapeHtml: function(str) {
            if (!str) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    };

    $(document).ready(function() {
        WPPPlugin.init();
    });

})(jQuery);
