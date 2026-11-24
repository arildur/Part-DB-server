/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2026 Jan Böhmer (https://github.com/jbtronics)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */


import {Controller} from "@hotwired/stimulus";

/**
 * Shows and hides detail rows of a table by toggle buttons. Each toggle is a <button> naming the rows it controls by
 * aria-controls (IDs separated by spaces) and telling their state by aria-expanded; the rendered state is the initial
 * one and nothing is remembered across reloads. A chevron icon in the button follows the state.
 *   <table data-controller="elements--expandable-rows">
 *     <button data-elements--expandable-rows-target="toggle" data-action="elements--expandable-rows#toggle"
 *             aria-controls="detail-1" aria-expanded="false">...</button>
 *     <tr id="detail-1" hidden>...</tr>
 * An optional button with the target "all" and the action toggleAll expands all rows, or collapses all when all are
 * expanded already.
 */
export default class extends Controller {
    static targets = ['toggle', 'all'];

    connect() {
        this._updateAll();
    }

    toggle(event) {
        const button = event.currentTarget;
        this._set(button, button.getAttribute('aria-expanded') !== 'true');
        this._updateAll();
    }

    toggleAll() {
        const expand = !this._allExpanded();
        this.toggleTargets.forEach(button => this._set(button, expand));
        this._updateAll();
    }

    _set(button, expanded) {
        button.setAttribute('aria-expanded', String(expanded));
        (button.getAttribute('aria-controls') ?? '').split(/\s+/).filter(id => id !== '').forEach(id => {
            const row = document.getElementById(id);
            if (row) {
                row.hidden = !expanded;
            }
        });
        const icon = button.querySelector('i');
        if (icon) {
            icon.classList.toggle('fa-chevron-down', expanded);
            icon.classList.toggle('fa-chevron-right', !expanded);
        }
    }

    _allExpanded() {
        return this.toggleTargets.length > 0
            && this.toggleTargets.every(button => button.getAttribute('aria-expanded') === 'true');
    }

    /**
     * The "all" button says what a click on it does: collapse when all are expanded, otherwise expand.
     */
    _updateAll() {
        const collapse = this._allExpanded();
        this.allTargets.forEach(button => {
            button.setAttribute('aria-expanded', String(collapse));
            const label = collapse ? button.dataset.labelCollapse : button.dataset.labelExpand;
            if (label) {
                button.setAttribute('aria-label', label);
            }
            const icon = button.querySelector('i');
            if (icon) {
                //Like the rows' chevrons: down when all are expanded, right otherwise
                icon.classList.toggle('fa-angles-down', collapse);
                icon.classList.toggle('fa-angles-right', !collapse);
            }
        });
    }
}
