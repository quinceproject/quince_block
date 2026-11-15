/**
 * Quince Block: skrypty panelu.
 *
 * Ładowany po panel.js silnika (daje `window.quinceToast`) i przed Alpine, więc
 * komponenty `quinceBlockEdit` i `quinceBlockRules` istnieją, zanim Alpine
 * przeczyta `x-data`.
 */
(function () {
    'use strict';

    function root() {
        return document.querySelector('.qc-root');
    }

    function texts() {
        try {
            return JSON.parse((root() && root().getAttribute('data-qb-i18n')) || '{}');
        } catch (e) {
            return {};
        }
    }

    function toast(message, type) {
        if (window.quinceToast) {
            window.quinceToast(message, type || 'success');
        }
    }

    /**
     * POST z JSON-em i tokenem panelu w nagłówku. Odpowiedź zawsze w kształcie
     * `{ok: true, ...}` albo `{ok: false, error: '...'}`; błąd idzie do toastu.
     */
    function post(url, data) {
        var t = texts();

        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': (root() && root().getAttribute('data-qb-csrf')) || ''
            },
            body: JSON.stringify(data || {})
        }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, error: response.status === 403 ? t.denied : t.failed };
            });
        }, function () {
            return { ok: false, error: t.network };
        }).then(function (result) {
            if (!result || !result.ok) {
                toast((result && result.error) || t.failed, 'danger');
            }
            return result || { ok: false };
        });
    }

    /** Adres GET z parametrami. Trasy panelu mają już `_token`, więc bez doklejania `?`. */
    function withParams(url, params) {
        var address = new URL(url, window.location.origin);
        Object.keys(params).forEach(function (key) {
            address.searchParams.set(key, params[key]);
        });
        return address.toString();
    }

    window.QB = {
        post: post,
        toast: toast,

        /** Przełącznik z listy: przy błędzie wraca do poprzedniego położenia. */
        toggle: function (url, data, checkbox) {
            checkbox.disabled = true;
            post(url, data).then(function (result) {
                checkbox.disabled = false;
                if (!result.ok) {
                    checkbox.checked = !checkbox.checked;
                }
            });
        }
    };

    /**
     * Edycja bloku: zakładki sekcji, wybór sklepu i języka treści, wstawianie
     * placeholderów w miejsce kursora.
     */
    window.quinceBlockEdit = function (cfg) {
        return {
            tab: cfg.tab || 'content',
            shop: cfg.shop,
            lang: cfg.lang,
            ab: !!cfg.ab,
            logic: cfg.logic || 'and',
            days: cfg.days || [],
            ruleCount: cfg.ruleCount || 0,
            langOpen: false,
            tick: 0,
            lastField: null,

            init: function () {
                // Sekcja w adresie: odświeżenie strony wraca do tej samej zakładki.
                this.$watch('tab', function (value) {
                    var address = new URL(window.location.href);
                    address.searchParams.set('section', value);
                    window.history.replaceState(null, '', address.toString());
                });
            },

            remember: function (el) {
                this.lastField = el;
            },

            /** Kropka przy języku: czy główna treść w tym sklepie i języku jest wpisana. */
            filled: function (shop, lang) {
                void this.tick;
                var field = document.querySelector('textarea[name="content[' + shop + '][' + lang + '][content]"]');
                return !!field && field.value.trim() !== '';
            },

            insert: function (token) {
                var field = this.lastField && this.lastField.offsetParent !== null ? this.lastField : null;

                if (!field) {
                    field = document.querySelector('textarea[name="content[' + this.shop + '][' + this.lang + '][content]"]');
                }

                if (!field) {
                    return;
                }

                var start = field.selectionStart || 0;
                var end = field.selectionEnd || start;
                field.value = field.value.slice(0, start) + token + field.value.slice(end);
                field.focus();
                field.selectionStart = field.selectionEnd = start + token.length;
                field.dispatchEvent(new Event('input', { bubbles: true }));
            }
        };
    };

    /** Reguły bloku: lista, formularz z definicji typów, zapis przez JSON. */
    window.quinceBlockRules = function () {
        var data = {};

        try {
            data = JSON.parse(document.getElementById('qb-rules-data').textContent || '{}');
        } catch (e) {
            data = {};
        }

        var choices = data.choices || {};
        var names = {};

        Object.keys(choices).forEach(function (source) {
            names[source] = {};
            (choices[source] || []).forEach(function (item) {
                names[source][item.id] = item.name;
            });
        });
        names.product = data.productNames || {};

        return {
            idBlock: data.idBlock || 0,
            types: data.types || [],
            rules: data.rules || [],
            urls: data.urls || {},
            texts: data.texts || {},
            editing: null,
            busy: false,
            error: '',
            uiState: {},
            featureValues: [],

            definition: function (type) {
                for (var i = 0; i < this.types.length; i++) {
                    if (this.types[i].type === type) {
                        return this.types[i];
                    }
                }
                return null;
            },

            fields: function () {
                var def = this.editing && this.definition(this.editing.type);
                return def ? def.fields : [];
            },

            emptyValue: function (type) {
                var value = {};
                var def = this.definition(type);

                (def ? def.fields : []).forEach(function (field) {
                    if (field.kind === 'bool') {
                        value[field.name] = false;
                    } else if (field.multiple) {
                        value[field.name] = [];
                    } else {
                        value[field.name] = '';
                    }
                });

                return value;
            },

            resetUi: function () {
                var state = {};
                this.fields().forEach(function (field) {
                    state[field.name] = { q: '', open: false, results: [] };
                });
                this.uiState = state;
                this.error = '';
            },

            ui: function (field) {
                return this.uiState[field.name] || { q: '', open: false, results: [] };
            },

            start: function () {
                var type = this.types.length ? this.types[0].type : '';
                this.editing = { id_rule: 0, type: type, mode: 'include', value: this.emptyValue(type) };
                this.featureValues = [];
                this.resetUi();
            },

            edit: function (rule) {
                var value = this.emptyValue(rule.type);
                var def = this.definition(rule.type);

                (def ? def.fields : []).forEach(function (field) {
                    var current = rule.value ? rule.value[field.name] : undefined;

                    if (current === undefined || current === null) {
                        return;
                    }

                    if (field.kind === 'bool') {
                        value[field.name] = !!current;
                    } else if (field.multiple) {
                        value[field.name] = Array.isArray(current) ? current.slice() : [current];
                    } else {
                        value[field.name] = String(current);
                    }
                });

                this.editing = { id_rule: rule.id, type: rule.type, mode: rule.mode, value: value };
                this.featureValues = [];
                this.resetUi();

                if (value.id_feature) {
                    this.loadFeatureValues(true);
                }
            },

            resetValue: function () {
                this.editing.value = this.emptyValue(this.editing.type);
                this.featureValues = [];
                this.resetUi();
            },

            cancel: function () {
                this.editing = null;
                this.error = '';
            },

            closeMenu: function (field) {
                if (this.uiState[field.name]) {
                    this.uiState[field.name].open = false;
                }
            },

            sourceList: function (source) {
                return source === 'feature_value' ? this.featureValues : (choices[source] || []);
            },

            nameOf: function (source, id) {
                return (names[source] && names[source][id]) || ('#' + id);
            },

            search: function (field) {
                var state = this.uiState[field.name];
                var picked = (this.editing.value[field.name] || []).map(Number);

                if (!state) {
                    return;
                }

                if (field.source === 'product') {
                    if (!state.q.trim()) {
                        state.results = [];
                        return;
                    }

                    fetch(withParams(this.urls.lookup, { source: 'product', q: state.q }), { credentials: 'same-origin' })
                        .then(function (r) { return r.ok ? r.json() : { items: [] }; })
                        .then(function (result) {
                            state.results = (result.items || []).filter(function (item) {
                                names.product[item.id] = item.name;
                                return picked.indexOf(item.id) === -1;
                            });
                        })
                        .catch(function () { state.results = []; });
                    return;
                }

                var q = state.q.trim().toLowerCase();

                state.results = this.sourceList(field.source).filter(function (item) {
                    if (picked.indexOf(item.id) !== -1) {
                        return false;
                    }
                    return !q
                        || String(item.name).toLowerCase().indexOf(q) !== -1
                        || String(item.path || '').toLowerCase().indexOf(q) !== -1
                        || String(item.id) === q;
                }).slice(0, 50);
            },

            pick: function (field, item) {
                var list = this.editing.value[field.name] || [];

                if (list.map(Number).indexOf(item.id) === -1) {
                    list.push(item.id);
                }

                this.editing.value[field.name] = list;
                this.uiState[field.name].q = '';
                this.search(field);
            },

            dropValue: function (field, value) {
                this.editing.value[field.name] = (this.editing.value[field.name] || []).filter(function (v) {
                    return String(v) !== String(value);
                });
            },

            addWord: function (field, input) {
                var list = this.editing.value[field.name] || [];

                input.value.split(',').forEach(function (word) {
                    word = word.trim();
                    if (word && list.indexOf(word) === -1) {
                        list.push(word);
                    }
                });

                this.editing.value[field.name] = list;
                input.value = '';
            },

            /** Wartości cechy po wyborze cechy. `keep`: przy edycji zostaw wybraną wartość. */
            loadFeatureValues: function (keep) {
                var self = this;
                var idFeature = this.editing.value.id_feature;

                if (!keep && 'id_value' in this.editing.value) {
                    this.editing.value.id_value = '';
                }

                if (!idFeature) {
                    this.featureValues = [];
                    return;
                }

                fetch(withParams(this.urls.lookup, { source: 'feature_value', id_feature: idFeature }), { credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : { items: [] }; })
                    .then(function (result) { self.featureValues = result.items || []; })
                    .catch(function () { self.featureValues = []; });
            },

            save: function () {
                var self = this;

                // Ostatnie słowo wpisane bez Entera też ma trafić do reguły.
                document.querySelectorAll('.qc-tags-input[type="text"]').forEach(function (input) {
                    if (input.value.trim()) {
                        input.dispatchEvent(new Event('blur'));
                    }
                });

                this.busy = true;
                this.error = '';

                post(this.urls.save, {
                    id_block: this.idBlock,
                    id_rule: this.editing.id_rule,
                    type: this.editing.type,
                    mode: this.editing.mode,
                    value: this.editing.value
                }).then(function (result) {
                    self.busy = false;

                    if (!result.ok) {
                        self.error = result.error || '';
                        return;
                    }

                    self.rules = result.rules || [];
                    self.editing = null;
                    toast(result.message, 'success');
                    window.dispatchEvent(new CustomEvent('qb-rules-changed', { detail: self.rules.length }));
                });
            },

            remove: function (rule) {
                var self = this;

                if (!window.confirm(this.texts.confirmDelete)) {
                    return;
                }

                this.busy = true;

                post(this.urls['delete'], { id_block: this.idBlock, id_rule: rule.id }).then(function (result) {
                    self.busy = false;

                    if (result.ok) {
                        self.rules = result.rules || [];
                        toast(result.message, 'success');
                        window.dispatchEvent(new CustomEvent('qb-rules-changed', { detail: self.rules.length }));
                    }
                });
            }
        };
    };
})();
