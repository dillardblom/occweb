(function (OC, window, $, undefined) {
  'use strict';
  $(function() {
    // Nextcloud core only auto-attaches the CSRF requesttoken header to ajax
    // calls made through its own globally-loaded jQuery. Since NC34 that
    // global jQuery is no longer guaranteed to be the one still active by
    // the time our own calls fire (other apps loading their own jQuery can
    // replace window.jQuery afterwards - see js/jquery.js), ajaxSetup() here
    // isn't reliable; every $.ajax() call below sets the header explicitly.

    function scrollToBottom(){
      var html = $('html');
      html.scrollTop(html.prop('scrollHeight'));
    }
    var baseUrl = OC.generateUrl('/apps/extended_occweb');

    // occ output is plain text with ANSI colors, but jQuery Terminal reads
    // "[" and "]" as its own formatting syntax. Escape the brackets in the
    // text (e.g. Symfony's "[alias]" in `occ list`) and leave the ANSI
    // sequences alone, so from_ansi can still turn them into colors. This
    // only works together with unixFormattingEscapeBrackets (see below).
    //
    // Output can contain text from users (display names, file names), so
    // keep only color codes (SGR) and drop other escape sequences,
    // backspaces and control characters, which could rewrite or hide text
    // on screen. A lone CR becomes a newline.
    function sanitizeOccOutput(output) {
      return String(output)
        .replace(/\x1B\][^\x07\x1B]*(\x07|\x1B\\)?/g, '')
        .replace(/(\x1B\[[0-9;]*m)|\x1B\[[0-?]*[ -\/]*[@-~]|\x1B[@-_]?/g, function (match, sgr) {
          return sgr || '';
        })
        .replace(/\r\n/g, '\n')
        .replace(/\r/g, '\n')
        .replace(/[\x00-\x08\x0B\x0C\x0E-\x1A\x1C-\x1F\x7F]/g, '')
        .replace(/(\x1B\[[0-9;]*m)|[\[\]]/g, function (match, sgr) {
          return sgr ? sgr : (match === '[' ? '&#91;' : '&#93;');
        });
    }

    // Current terminal mode: 'occ' (regular occ commands) or 'sql'
    // (running arbitrary SQL queries via /db/query).
    var mode = 'occ';

    var OCC_PROMPT = 'occ $ ';
    var SQL_PROMPT = '[[;#ff5555;]sql]# ';

    // Splits a batch of queries on ";" while respecting single AND double
    // quotes (incl. '' / "" as an escaped quote inside a string/identifier),
    // so that a ";" inside a string literal or "quoted identifier" doesn't
    // break the split. Mirrors splitStatements() on the backend.
    function splitStatements(sql) {
      var statements = [];
      var current = '';
      var quoteChar = null;
      for (var i = 0; i < sql.length; i++) {
        var ch = sql[i];
        if (quoteChar !== null) {
          if (ch === quoteChar) {
            if (sql[i + 1] === quoteChar) {
              current += quoteChar + quoteChar;
              i++;
              continue;
            }
            quoteChar = null;
          }
          current += ch;
          continue;
        }
        if (ch === "'" || ch === '"') {
          quoteChar = ch;
          current += ch;
          continue;
        }
        if (ch === ';') {
          statements.push(current.trim());
          current = '';
          continue;
        }
        current += ch;
      }
      if (current.trim() !== '') {
        statements.push(current.trim());
      }
      return statements.filter(function (s) { return s !== ''; });
    }

    // Quick client-side check for DELETE/UPDATE — purely for UX (to avoid
    // an extra round trip to the server). The backend always makes the
    // final call (requiresConfirmation) and also covers every other
    // statement that changes something; this is just a hint.
    function scriptNeedsConfirmation(sql) {
      return splitStatements(sql).some(function (part) {
        var normalized = part.replace(/^(\s*--[^\n]*\n)*\s*/, '');
        return /^(DELETE|UPDATE)\b/i.test(normalized);
      });
    }

    function escapeSqlString(value) {
      return String(value).replace(/'/g, "''");
    }

    // Ready-made SQL script templates. build() returns the script text,
    // which is inserted into the command line via term.set_command() —
    // nothing runs automatically, the user reviews it themselves and
    // presses Enter (which then triggers the normal DELETE confirmation).
    var TEMPLATES = {
      'delete-user': {
        args: ['uid'],
        description: 'Completely delete a local user (oc_preferences, oc_group_user, oc_ldap_user_mapping, oc_users)',
        build: function (uid) {
          // The value is substituted directly into each query (rather than
          // via SET+current_setting) — this keeps the script working even
          // if the user copies/runs only some of the lines individually.
          var v = escapeSqlString(uid);
          return [
            "SELECT * FROM oc_users WHERE uid = '" + v + "'",
            "SELECT * FROM oc_preferences WHERE userid = '" + v + "'",
            "DELETE FROM oc_preferences WHERE userid = '" + v + "'",
            "DELETE FROM oc_group_user WHERE uid = '" + v + "'",
            "DELETE FROM oc_ldap_user_mapping WHERE owncloud_name = '" + v + "'",
            "DELETE FROM oc_users WHERE uid = '" + v + "'"
          ].join(';\n') + ';';
        }
      },
      'list-user': {
        args: ['uid'],
        description: "View a user's data only, without deleting anything (oc_users, oc_preferences, oc_group_user, oc_ldap_user_mapping)",
        build: function (uid) {
          var v = escapeSqlString(uid);
          return [
            "SELECT * FROM oc_users WHERE uid = '" + v + "'",
            "SELECT * FROM oc_preferences WHERE userid = '" + v + "'",
            "SELECT * FROM oc_group_user WHERE uid = '" + v + "'",
            "SELECT * FROM oc_ldap_user_mapping WHERE owncloud_name = '" + v + "'"
          ].join(';\n') + ';';
        }
      },
      'rename-user': {
        args: ['old_uid', 'new_uid'],
        description: "WARNING: not an official Nextcloud operation. Renames the uid only in the core tables — doesn't cover everything, requires manual extra steps (see the warning in the script itself)",
        build: function (oldUid, newUid) {
          var o = escapeSqlString(oldUid);
          var n = escapeSqlString(newUid);
          // The warning consists only of single-line "--" comments with no
          // ";" inside them, so splitStatements() (mirrored on the backend
          // and here) won't split them into separate statements, and
          // stripLeadingComments() on the backend will strip this block
          // before determining the type of the very first query (SELECT).
          var warning =
            "-- WARNING rename is NOT an officially supported Nextcloud operation\n" +
            "-- This only updates core tables below - it does NOT cover app-specific\n" +
            "-- tables (Talk, Calendar, Contacts, Mail, two-factor, WebAuthn, etc.)\n" +
            "-- After running this you must ALSO, with the web server stopped or in\n" +
            "-- maintenance mode, rename the data directory on disk (data/" + o + " -> data/" + n + ")\n" +
            "-- and then run: occ files:scan --all\n" +
            "-- Back up the database first and test on a non-critical account\n";
          var statements = [
            "SELECT uid FROM oc_users WHERE uid = '" + o + "'",
            "SELECT uid FROM oc_users WHERE uid = '" + n + "'",
            "UPDATE oc_users SET uid = '" + n + "' WHERE uid = '" + o + "'",
            "UPDATE oc_preferences SET userid = '" + n + "' WHERE userid = '" + o + "'",
            "UPDATE oc_group_user SET uid = '" + n + "' WHERE uid = '" + o + "'",
            "UPDATE oc_group_admin SET uid = '" + n + "' WHERE uid = '" + o + "'",
            "UPDATE oc_ldap_user_mapping SET owncloud_name = '" + n + "' WHERE owncloud_name = '" + o + "'",
            "UPDATE oc_share SET uid_owner = '" + n + "' WHERE uid_owner = '" + o + "'",
            "UPDATE oc_share SET uid_initiator = '" + n + "' WHERE uid_initiator = '" + o + "'",
            "UPDATE oc_share SET share_with = '" + n + "' WHERE share_with = '" + o + "' AND share_type = 0",
            "UPDATE oc_mounts SET user_id = '" + n + "' WHERE user_id = '" + o + "'",
            "UPDATE oc_storages SET id = 'home::" + n + "' WHERE id = 'home::" + o + "'",
            "SELECT uid FROM oc_users WHERE uid = '" + n + "'"
          ];
          return warning + statements.join(';\n') + ';';
        }
      }
    };

    function listTemplates(term) {
      term.echo('[[;yellow;]Available templates:]');
      Object.keys(TEMPLATES).forEach(function (name) {
        var t = TEMPLATES[name];
        var usage = name + ' ' + t.args.map(function (a) { return '<' + a + '>'; }).join(' ');
        term.echo('[[;#009ae3;]  template ' + usage + ']');
        term.echo('    ' + t.description);
      });
      term.echo('[[;gray;]Fills the command line — review it, then press Enter to run.]');
    }

    function useTemplate(term, name, args) {
      var t = TEMPLATES[name];
      if (!t) {
        term.echo('[[;#ff5555;]Unknown template: ]' + $.terminal.escape_formatting(name || '') + '. Type "templates" to list available ones.');
        return;
      }
      if (args.length < t.args.length) {
        var usage = name + ' ' + t.args.map(function (a) { return '<' + a + '>'; }).join(' ');
        term.echo('[[;#ff5555;]Missing arguments. Usage: ]template ' + usage);
        return;
      }
      var sql = t.build.apply(null, args);
      term.set_command(sql);
      term.echo('[[;yellow;]Template inserted into the command line — review it, then press Enter to run.]');
    }

    // Tabular rendering of SELECT results, psql-style.
    function renderTable(term, rows) {
      if (!rows || !rows.length) {
        return;
      }
      var columns = Object.keys(rows[0]);

      function formatCell(v) {
        if (v === null || v === undefined) {
          // An explicit label rather than an empty string — otherwise NULL
          // would be indistinguishable from an actual empty string '' in
          // the table output.
          return '[NULL]';
        }
        if (typeof v === 'object') {
          return JSON.stringify(v);
        }
        return String(v).replace(/\r?\n/g, '\\n');
      }

      function pad(str, width) {
        str = String(str);
        var diff = width - str.length;
        return diff > 0 ? str + new Array(diff + 1).join(' ') : str;
      }

      var widths = columns.map(function (col) {
        return rows.reduce(function (max, row) {
          return Math.max(max, formatCell(row[col]).length);
        }, col.length);
      });

      function formatRow(cells) {
        return cells.map(function (cell, i) {
          return ' ' + pad(cell, widths[i]) + ' ';
        }).join('|');
      }

      var lines = [];
      lines.push(formatRow(columns));
      lines.push(widths.map(function (w) { return new Array(w + 3).join('-'); }).join('+'));
      rows.forEach(function (row) {
        lines.push(formatRow(columns.map(function (col) { return formatCell(row[col]); })));
      });

      term.echo($.terminal.escape_formatting(lines.join('\n')));
    }

    function renderSqlResponse(term, response) {
      if (!response) {
        term.echo('[[;#ff5555;]Empty response from server]');
        return;
      }
      if (response.success === false) {
        term.echo('[[;#ff5555;]Error: ]' + $.terminal.escape_formatting(response.error || 'unknown error'));
        return;
      }
      var results = response.results || [];
      if (!results.length) {
        term.echo('[[;yellow;]No statements were executed]');
        return;
      }
      results.forEach(function (r) {
        term.echo('[[;#009ae3;]> ]' + $.terminal.escape_formatting(r.query || ''));
        if (r.type === 'error') {
          term.echo('[[;#ff5555;]  Error: ]' + $.terminal.escape_formatting(r.error || 'unknown error'));
        } else if (r.type === 'select') {
          term.echo('[[;gray;]  ' + r.count + ' row(s)]');
          if (r.count > 0) {
            renderTable(term, r.data);
          }
          if (r.truncated) {
            term.echo('[[;yellow;]  Result truncated — showing only the first ' + r.count + ' rows, add LIMIT to see more precisely.]');
          }
        } else if (r.type === 'set') {
          term.echo('[[;green;]  OK (session variable set)]');
        } else if (r.type === 'delete') {
          term.echo('[[;#ff9900;]  DELETED ' + r.affected_rows + ' row(s)]');
        } else if (r.type === 'update') {
          term.echo('[[;#ff9900;]  UPDATED ' + r.affected_rows + ' row(s)]');
        } else {
          term.echo('[[;green;]  OK, ' + r.affected_rows + ' row(s) affected]');
        }
      });
      if (response.rolledBack) {
        term.echo('[[;#ff5555;]Batch failed partway through — all statements in this batch were rolled back.]');
      } else if (response.rollbackFailed) {
        term.echo('[[;#ff0000;]' + $.terminal.escape_formatting(response.warning || 'Batch failed and the rollback itself failed — earlier statements may have been permanently applied. Check manually.') + ']');
      }
    }

    function enterSqlMode(term) {
      mode = 'sql';
      term.set_prompt(SQL_PROMPT);
      term.echo('[[;yellow;]Switched to SQL mode. Admin only — statements run directly against the database.]');
      term.echo('[[;gray;]Separate statements with ";". Shift+Enter for a new line, Enter to run. Type "occ" to go back.]');
      term.echo('[[;gray;]Type "templates" to list ready-made scripts (e.g. deleting a user).]');
    }

    function exitSqlMode(term) {
      mode = 'occ';
      term.set_prompt(OCC_PROMPT);
      term.echo('[[;yellow;]Switched back to OCC mode.]');
    }

    // Timeout for large/long-running batches (e.g. a DELETE on a big table
    // without an index): without it, a hung query would silently leave the
    // terminal blocked (term.pause()) forever if the server never responds.
    var SQL_REQUEST_TIMEOUT_MS = 120000;

    function sendSqlQuery(term, sql, confirmed) {
      term.pause();
      $.ajax({
        url: baseUrl + '/db/query',
        type: 'POST',
        contentType: 'application/json',
        timeout: SQL_REQUEST_TIMEOUT_MS,
        headers: { requesttoken: OC.requestToken },
        data: JSON.stringify({ sql: sql, confirm: !!confirmed })
      }).done(function (response) {
        if (response && response.requiresConfirmation) {
          term.resume();
          askDeleteConfirmation(term, sql, response.error);
          return;
        }
        renderSqlResponse(term, response);
        term.resume();
      }).fail(function (xhr, status) {
        if (status === 'timeout') {
          term.echo('[[;#ff5555;]Request timed out after ' + (SQL_REQUEST_TIMEOUT_MS / 1000) + 's — the query may still be running on the server, check occ/DB logs before retrying.]');
        } else {
          term.echo('[[;#ff5555;]Request failed: ]' + $.terminal.escape_formatting(xhr.status + ' ' + xhr.statusText));
        }
        term.resume();
      });
    }

    function askDeleteConfirmation(term, sql, message) {
      var text = message || 'This script contains statement(s) that change data or the schema.';
      var prompt = '[[;#ff5555;]' + $.terminal.escape_brackets(text) + ' Type "yes" to run it: ]';
      term.read(prompt).then(function (answer) {
        if ((answer || '').trim().toLowerCase() === 'yes') {
          sendSqlQuery(term, sql, true);
        } else {
          term.echo('[[;yellow;]Cancelled — nothing was executed.]');
        }
      }, function () {
        term.echo('[[;yellow;]Cancelled — nothing was executed.]');
      });
    }

    $.ajax({ url: baseUrl + '/cmd', headers: { requesttoken: OC.requestToken } }).done(function(response){
      $('#app-content').terminal(function(command, term) {
        if (mode === 'sql') {
          var trimmed = command.trim();
          if (trimmed === 'occ') {
            exitSqlMode(term);
            return;
          }
          if (trimmed === 'c') {
            term.clear();
            return;
          }
          if (trimmed === 'exit') {
            exitSqlMode(term);
            term.reset();
            return;
          }
          if (trimmed === 'templates') {
            listTemplates(term);
            return;
          }
          if (/^template(\s|$)/i.test(trimmed)) {
            var parts = trimmed.split(/\s+/);
            useTemplate(term, parts[1], parts.slice(2));
            return;
          }
          if (!trimmed) {
            return;
          }
          if (scriptNeedsConfirmation(command)) {
            askDeleteConfirmation(term, command);
          } else {
            sendSqlQuery(term, command, false);
          }
          return;
        }

        switch (command) {
        case "c":
          this.clear();
          break;
        case "exit":
          this.reset();
          break;
        case "sql":
          enterSqlMode(term);
          break;
        default:
          var occCommand = {
            command: command
          };
          term.pause();
          $.ajax({
            url: baseUrl + '/cmd',
            type: 'POST',
            contentType: 'application/json',
            headers: { requesttoken: OC.requestToken },
            data: JSON.stringify(occCommand)
          }).done(function (response) {
            term.echo('\n' + sanitizeOccOutput(response)).resume();
          }).fail(function (xhr, status) {
            term.echo('\n[[;#ff5555;]Request failed: ]' + $.terminal.escape_formatting(xhr.status + ' ' + xhr.statusText)).resume();
          });
        }
      }, {
        greetings: function (callback) {
          callback('[[;green;]' + new Date().toString().slice(0, 24) + "]\n\nPress [[;#ff5e99;]Enter] for more information on [[;#009ae3;]occ] commands.\nType [[;#ff5e99;]sql] to switch to SQL query mode.\n")
        },
        name: 'occ',
        prompt: OCC_PROMPT,
        completion: response,
        // The overtyping and from_ansi formatters unescape brackets before
        // parsing; this makes them escape the text again afterwards.
        unixFormattingEscapeBrackets: true,
        // Output can contain user-controlled text; don't turn URLs in it
        // into links an admin might click by mistake.
        convertLinks: false,
        keydown: function (e) {
          // Shift+Enter inserts a newline instead of running the command,
          // letting you type multi-line SQL scripts in sql mode.
          if (e.shiftKey && e.key === 'Enter') {
            this.insert('\n');
            return false;
          }
        },
        onResize: function(){
          scrollToBottom()
        }
      });
    }).fail(function (xhr, status) {
      // Without this, a failure here (expired session/CSRF token, network
      // error, a rejected admin check) leaves #app-content permanently
      // empty with no visible error and nothing in the console pointing
      // at the cause - exactly what happened during NC34 testing before
      // this handler existed.
      $('#app-content').text(
        'Failed to load the terminal: ' + xhr.status + ' ' + xhr.statusText +
        '. Try reloading the page; if that keeps happening, check the server logs.'
      );
    });
    $('html').keypress(function(){
      scrollToBottom()
    })
  });
})(OC, window, jQuery);
