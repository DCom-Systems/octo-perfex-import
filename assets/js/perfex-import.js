/**
 * Perfex CRM Import Wizard
 * Steuert den 5-Schritte-Wizard für den Perfex-Import.
 */
(function ($) {
    'use strict';

    var PerfexImport = {

        currentStep: 1,
        importId: null,
        cancelled: false,

        // Kumulierte Statistiken über alle Batches
        totalStats: {
            companies_new:      0,
            companies_updated:  0,
            contacts_new:       0,
            contacts_updated:   0,
            invoice_recipients: 0,
            notes:              0,
            invoices_new:       0,
            skipped:            0,
            errors:             []
        },

        // DB-Config wird nach Step 1 gespeichert
        dbConfig: {},

        // Optionen aus Step 2
        options: {},

        init: function () {
            this.bindEvents();
        },

        bindEvents: function () {
            $('#pimport-btn-connect').on('click',         $.proxy(this.connect,          this));
            $('#pimport-btn-step1-next').on('click',      $.proxy(this.goToStep2,        this));
            $('#pimport-btn-step2-back').on('click',      function () { PerfexImport.showStep(1); });
            $('#pimport-btn-step2-next').on('click',      $.proxy(this.loadPreview,      this));
            $('#pimport-btn-step3-back').on('click',      function () { PerfexImport.showStep(2); });
            $('#pimport-btn-start').on('click',           $.proxy(this.startImport,      this));
            $('#pimport-btn-cancel').on('click',          $.proxy(this.cancelImport,     this));
            $('#pimport-btn-restart').on('click',         $.proxy(this.restart,          this));
        },

        // -----------------------------------------------------------------------
        // Step 1: Verbindung testen
        // -----------------------------------------------------------------------
        connect: function () {
            var self = this;
            var $btn  = $('#pimport-btn-connect');
            var $res  = $('#pimport-connect-result');

            $btn.prop('disabled', true).text(octoPerfexImportData.i18n.connecting);
            $res.hide();

            $.post(octoPerfexImportData.ajaxUrl, {
                action:    'octo_perfex_import_connect',
                nonce:     octoPerfexImportData.nonce,
                db_host:   $('#pimport-db-host').val(),
                db_user:   $('#pimport-db-user').val(),
                db_pass:   $('#pimport-db-pass').val(),
                db_name:   $('#pimport-db-name').val(),
                db_prefix: $('#pimport-db-prefix').val() || 'tbl'
            })
            .done(function (resp) {
                if (resp.success) {
                    var d = resp.data;
                    self.dbConfig = {
                        host:   $('#pimport-db-host').val(),
                        user:   $('#pimport-db-user').val(),
                        pass:   $('#pimport-db-pass').val(),
                        name:   $('#pimport-db-name').val(),
                        prefix: $('#pimport-db-prefix').val() || 'tbl'
                    };

                    var invRow = d.invoices > 0
                        ? '<tr><td>Rechnungen:</td><td><strong>' + d.invoices + '</strong></td></tr>'
                        : '';
                    $res.html(
                        '<div class="octo-import-success" style="background:#f0faf0;border:1px solid #7dc97d;padding:12px 16px;border-radius:4px;">' +
                        '<strong>&#10003; Verbunden!</strong><br>' +
                        '<table style="margin-top:8px; border-collapse:collapse;">' +
                        '<tr><td style="padding:2px 16px 2px 0;">Firmen gesamt:</td><td><strong>' + d.companies + '</strong></td>' +
                        '<td style="padding:2px 0 2px 16px;">davon aktiv:</td><td><strong>' + d.companies_active + '</strong></td></tr>' +
                        '<tr><td>Kontakte:</td><td><strong>' + d.contacts + '</strong></td>' +
                        '<td style="padding:2px 0 2px 16px;">Rechnungsempfänger:</td><td><strong style="color:#E09000;">' + d.invoice_recipients + '</strong></td></tr>' +
                        '<tr><td>Notizen:</td><td><strong>' + d.notes + '</strong></td></tr>' +
                        invRow +
                        '</table></div>'
                    ).show();
                    $('#pimport-btn-step1-next').show();
                } else {
                    $res.html('<div class="octo-import-error" style="background:#fff3f3;border:1px solid #f5c6c6;padding:12px 16px;border-radius:4px;color:#c33;">' +
                        '<strong>Verbindung fehlgeschlagen:</strong> ' + (resp.data.message || 'Unbekannter Fehler') + '</div>').show();
                }
            })
            .fail(function () {
                $res.html('<div class="octo-import-error" style="background:#fff3f3;border:1px solid #f5c6c6;padding:12px;border-radius:4px;color:#c33;">Server-Fehler beim Verbinden.</div>').show();
            })
            .always(function () {
                $btn.prop('disabled', false).text('Verbindung testen');
            });
        },

        goToStep2: function () {
            this.showStep(2);
        },

        // -----------------------------------------------------------------------
        // Step 3: Vorschau laden
        // -----------------------------------------------------------------------
        loadPreview: function () {
            var self    = this;
            var $btn    = $('#pimport-btn-step2-next');
            var $table  = $('#pimport-preview-table');

            this.saveOptions();
            $btn.prop('disabled', true).text('Lade Vorschau…');
            $table.html('<p>Lade…</p>');
            this.showStep(3);

            $.post(octoPerfexImportData.ajaxUrl, this.buildPostData({
                action:           'octo_perfex_import_preview',
                include_inactive: this.options.include_inactive ? '1' : '0'
            }))
            .done(function (resp) {
                if (resp.success) {
                    $table.html(self.renderPreviewTable(resp.data.preview));
                } else {
                    $table.html('<p style="color:#c33;">' + (resp.data.message || 'Fehler') + '</p>');
                }
            })
            .fail(function () {
                $table.html('<p style="color:#c33;">Server-Fehler beim Laden der Vorschau.</p>');
            })
            .always(function () {
                $btn.prop('disabled', false).text('Vorschau laden →');
            });
        },

        renderPreviewTable: function (items) {
            if (!items || !items.length) {
                return '<p>Keine Datensätze gefunden.</p>';
            }

            var html = '<table class="octo-table" style="width:100%; border-collapse:collapse;">';
            html += '<thead><tr>' +
                '<th style="padding:8px; text-align:left;">Firma</th>' +
                '<th style="padding:8px; text-align:left;">Stadt</th>' +
                '<th style="padding:8px; text-align:left;">Status</th>' +
                '<th style="padding:8px; text-align:left;">Kontakte</th>' +
                '</tr></thead><tbody>';

            items.forEach(function (item) {
                var contactList = '';
                if (item.contacts && item.contacts.length) {
                    item.contacts.forEach(function (c) {
                        var badges = '';
                        if (c.is_primary)        badges += ' <span style="background:#006B95;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;">Primär</span>';
                        if (c.invoice_recipient) badges += ' <span style="background:#E09000;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;">Rechnungsempfänger</span>';
                        contactList += '<div style="margin-bottom:2px;">' + $('<span>').text(c.name).html() +
                            ' <span style="color:#999; font-size:12px;">' + $('<span>').text(c.email).html() + '</span>' + badges + '</div>';
                    });
                } else {
                    contactList = '<span style="color:#999;">Keine Kontakte</span>';
                }

                var status = item.active ? '<span style="color:green;">Aktiv</span>' : '<span style="color:#999;">Inaktiv</span>';

                html += '<tr style="border-top:1px solid #eee;">' +
                    '<td style="padding:8px;">' + $('<span>').text(item.company).html() + '</td>' +
                    '<td style="padding:8px;">' + $('<span>').text(item.city).html() + '</td>' +
                    '<td style="padding:8px;">' + status + '</td>' +
                    '<td style="padding:8px;">' + contactList + '</td>' +
                    '</tr>';
            });

            html += '</tbody></table>';
            return html;
        },

        saveOptions: function () {
            this.options = {
                include_inactive: $('input[name="pimport-scope"]:checked').val() === '1',
                owner_id:         $('#pimport-owner').val(),
                tag:              $('#pimport-tag').val(),
                import_notes:     $('#pimport-notes').is(':checked')
            };
        },

        // -----------------------------------------------------------------------
        // Step 4: Import starten
        // -----------------------------------------------------------------------
        startImport: function () {
            this.cancelled  = false;
            this.importId   = 'pimport_' + Date.now();
            this.totalStats = { companies_new:0, companies_updated:0, contacts_new:0, contacts_updated:0, invoice_recipients:0, notes:0, invoices_new:0, skipped:0, errors:[] };

            this.showStep(4);
            this.resetLiveStats();
            this.runPhase('companies', 0);
        },

        runPhase: function (phase, offset) {
            if (this.cancelled) return;

            var self       = this;
            var phaseTotal = { companies: this.dbConfig._companies || 0, contacts: this.dbConfig._contacts || 0, notes: this.dbConfig._notes || 0, invoices: this.dbConfig._invoices || 0 };
            var phaseName  = { companies: 'Firmen importieren…', contacts: 'Kontakte importieren…', notes: 'Notizen importieren…', invoices: 'Rechnungen importieren…' };

            $('#pimport-phase-label').text(phaseName[phase] || '');

            $.post(octoPerfexImportData.ajaxUrl, this.buildPostData({
                action:           'octo_perfex_import_batch',
                phase:            phase,
                offset:           offset,
                include_inactive: this.options.include_inactive ? '1' : '0',
                owner_id:         this.options.owner_id,
                tag:              this.options.tag,
                import_notes:     this.options.import_notes ? '1' : '0',
                import_id:        this.importId
            }))
            .done(function (resp) {
                if (!resp.success) {
                    self.showError(resp.data ? resp.data.message : 'Unbekannter Fehler');
                    return;
                }

                if (resp.data.cancelled) {
                    self.showStep(1);
                    return;
                }

                var s = resp.data.stats;
                self.accumulate(s);
                self.updateLiveStats();
                self.updateProgress(phase, offset, resp.data.is_complete);

                if (!resp.data.is_complete) {
                    self.runPhase(phase, resp.data.next_offset);
                } else {
                    // Nächste Phase
                    var next = { companies: 'contacts', contacts: 'notes', notes: 'invoices', invoices: null };
                    var nextPhase = next[phase];

                    if (!nextPhase) {
                        self.finishImport();
                    } else if (nextPhase === 'notes' && !self.options.import_notes) {
                        // Notizen übersprungen → direkt zu Rechnungen
                        self.runPhase('invoices', 0);
                    } else {
                        self.runPhase(nextPhase, 0);
                    }
                }
            })
            .fail(function () {
                self.showError('Netzwerkfehler beim Import. Bitte neu starten.');
            });
        },

        accumulate: function (s) {
            if (!s) return;
            this.totalStats.companies_new      += s.companies_new      || 0;
            this.totalStats.companies_updated  += s.companies_updated  || 0;
            this.totalStats.contacts_new       += s.contacts_new       || 0;
            this.totalStats.contacts_updated   += s.contacts_updated   || 0;
            this.totalStats.invoice_recipients += s.invoice_recipients || 0;
            this.totalStats.notes              += s.notes              || 0;
            this.totalStats.invoices_new       += s.invoices_new       || 0;
            this.totalStats.skipped            += s.skipped            || 0;
            if (s.errors && s.errors.length) {
                this.totalStats.errors = this.totalStats.errors.concat(s.errors);
            }
        },

        updateLiveStats: function () {
            var t = this.totalStats;
            $('#pimport-stat-companies').text(t.companies_new + t.companies_updated);
            $('#pimport-stat-contacts').text(t.contacts_new + t.contacts_updated);
            $('#pimport-stat-recipients').text(t.invoice_recipients);
            $('#pimport-stat-notes').text(t.notes);
            $('#pimport-stat-invoices').text(t.invoices_new);
            $('#pimport-stat-errors').text(t.errors.length);
        },

        updateProgress: function (phase, offset, isComplete) {
            var msgs = {
                companies: isComplete ? 'Firmen fertig.' : 'Firmen: ' + (offset + 20) + ' verarbeitet…',
                contacts:  isComplete ? 'Kontakte fertig.' : 'Kontakte: ' + offset + ' verarbeitet…',
                notes:     isComplete ? 'Notizen fertig.' : 'Notizen: ' + offset + ' verarbeitet…',
                invoices:  isComplete ? 'Rechnungen fertig.' : 'Rechnungen: ' + offset + ' verarbeitet…'
            };
            $('#pimport-progress-info').text(msgs[phase] || '');

            var pct = {
                companies: isComplete ? 25 : Math.min(22, offset / 2),
                contacts:  isComplete ? 50 : 25 + Math.min(22, offset / 3),
                notes:     isComplete ? 75 : 50 + Math.min(22, offset / 3),
                invoices:  isComplete ? 100 : 75 + Math.min(22, offset / 3)
            };
            $('#pimport-progress-fill').css('width', (pct[phase] || 0) + '%');
        },

        resetLiveStats: function () {
            $('#pimport-stat-companies, #pimport-stat-contacts, #pimport-stat-recipients, #pimport-stat-notes, #pimport-stat-invoices, #pimport-stat-errors').text('0');
            $('#pimport-progress-fill').css('width', '0%');
            $('#pimport-progress-info').text('');
        },

        finishImport: function () {
            var t = this.totalStats;
            $('#pimport-progress-fill').css('width', '100%');
            $('#pimport-phase-label').text('Import abgeschlossen!');

            // Ergebnis befüllen
            $('#pimport-result-companies-new').text(t.companies_new);
            $('#pimport-result-companies-updated').text(t.companies_updated);
            $('#pimport-result-contacts-new').text(t.contacts_new);
            $('#pimport-result-contacts-updated').text(t.contacts_updated);
            $('#pimport-result-recipients').text(t.invoice_recipients);
            $('#pimport-result-notes').text(t.notes);
            $('#pimport-result-invoices-new').text(t.invoices_new);
            $('#pimport-result-skipped').text(t.skipped);

            if (t.errors.length) {
                $('#pimport-result-errors').text(t.errors.length);
                $('#pimport-error-card').show();

                var $ul = $('#pimport-error-ul').empty();
                t.errors.forEach(function (e) {
                    $ul.append($('<li>').text(e));
                });
                $('#pimport-error-list').show();
            }

            setTimeout(function () { PerfexImport.showStep(5); }, 600);
        },

        showError: function (msg) {
            $('#pimport-phase-label').html('<span style="color:#c33;">Fehler: ' + msg + '</span>');
        },

        // -----------------------------------------------------------------------
        // Abbrechen
        // -----------------------------------------------------------------------
        cancelImport: function () {
            if (!confirm('Import wirklich abbrechen?')) return;
            this.cancelled = true;

            $.post(octoPerfexImportData.ajaxUrl, this.buildPostData({
                action:    'octo_perfex_import_cancel',
                import_id: this.importId
            }));
        },

        // -----------------------------------------------------------------------
        // Neu starten
        // -----------------------------------------------------------------------
        restart: function () {
            this.totalStats = { companies_new:0, companies_updated:0, contacts_new:0, contacts_updated:0, invoice_recipients:0, notes:0, invoices_new:0, skipped:0, errors:[] };
            $('#pimport-error-list, #pimport-error-card').hide();
            $('#pimport-error-ul').empty();
            this.showStep(1);
        },

        // -----------------------------------------------------------------------
        // Stepper
        // -----------------------------------------------------------------------
        showStep: function (step) {
            this.currentStep = step;

            for (var i = 1; i <= 5; i++) {
                $('#octo-pimport-step-' + i).toggle(i === step);

                var $s = $('[data-step="' + i + '"]');
                $s.toggleClass('active', i === step);
                $s.toggleClass('completed', i < step);
            }
        },

        // -----------------------------------------------------------------------
        // POST-Daten zusammenstellen (immer mit DB-Config)
        // -----------------------------------------------------------------------
        buildPostData: function (extra) {
            return $.extend({
                nonce:     octoPerfexImportData.nonce,
                db_host:   this.dbConfig.host   || '',
                db_user:   this.dbConfig.user   || '',
                db_pass:   this.dbConfig.pass   || '',
                db_name:   this.dbConfig.name   || '',
                db_prefix: this.dbConfig.prefix || 'tbl'
            }, extra);
        }
    };

    $(document).ready(function () {
        PerfexImport.init();

        // Gespeicherte DB-Config vorauffüllen
        var s = octoPerfexImportData.savedDb || {};
        if (s.host)   $('#pimport-db-host').val(s.host);
        if (s.name)   $('#pimport-db-name').val(s.name);
        if (s.user)   $('#pimport-db-user').val(s.user);
        if (s.pass)   $('#pimport-db-pass').val(s.pass);
        if (s.prefix) $('#pimport-db-prefix').val(s.prefix);
    });

}(jQuery));
