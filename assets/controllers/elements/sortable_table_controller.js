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
 * Makes a small, fully rendered table sortable by clicking its column headers. Put it on the <table>:
 *   <table data-controller="elements--sortable-table">
 * Each header of the last row of the <thead> becomes a button; a first click sorts the rows of the first <tbody>
 * ascending by that column, a further click toggles to descending. The sorted header gets aria-sort and an arrow.
 *
 * A cell sorts by its data-sort-value attribute if it has one, otherwise by its text. Values that are numbers compare
 * as numbers, all others as text in natural, locale aware order. Empty values go last in both directions.
 * A header with data-sortable="false" is left as it is. Body cells must not span columns.
 */
export default class extends Controller {
    connect() {
        this._collator = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});
        this._column = null;
        this._ascending = true;

        const tbody = this.element.tBodies[0];
        if (!tbody) {
            return;
        }
        //Remember the rendered order, so that equal values keep it
        Array.from(tbody.rows).forEach((row, index) => { row.dataset.sortOriginalIndex = String(index); });

        this._headers = this._headerCells();
        this._headers.forEach((th, column) => {
            if (th.dataset.sortable === 'false' || th.querySelector('button.sortable-table-button')) {
                return;
            }
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-link p-0 border-0 text-reset fw-bold text-decoration-none text-start sortable-table-button';
            while (th.firstChild) {
                button.appendChild(th.firstChild);
            }
            const arrow = document.createElement('i');
            arrow.className = 'fas fa-sort fa-fw ms-1 text-muted sortable-table-arrow';
            arrow.setAttribute('aria-hidden', 'true');
            button.appendChild(arrow);
            button.addEventListener('click', () => this.sortBy(column));
            th.appendChild(button);
            th.setAttribute('aria-sort', 'none');
        });
    }

    _headerCells() {
        const head = this.element.tHead;
        if (!head || head.rows.length === 0) {
            return [];
        }

        return Array.from(head.rows[head.rows.length - 1].cells);
    }

    /**
     * Sorts the rows by the given column index: ascending first, toggling on each further call for the same column.
     */
    sortBy(column) {
        this._ascending = this._column === column ? !this._ascending : true;
        this._column = column;

        const tbody = this.element.tBodies[0];
        const rows = Array.from(tbody.rows);
        const direction = this._ascending ? 1 : -1;
        rows.sort((a, b) => {
            const va = this._value(a.cells[column]);
            const vb = this._value(b.cells[column]);
            //Empty values last, whatever the direction
            if (va === null || vb === null) {
                if (va !== vb) {
                    return va === null ? 1 : -1;
                }
            } else {
                const result = (typeof va === 'number' && typeof vb === 'number')
                    ? va - vb
                    : this._collator.compare(String(va), String(vb));
                if (result !== 0) {
                    return result * direction;
                }
            }

            return Number(a.dataset.sortOriginalIndex) - Number(b.dataset.sortOriginalIndex);
        });
        rows.forEach(row => tbody.appendChild(row));

        this._headers.forEach((th, index) => {
            const arrow = th.querySelector('.sortable-table-arrow');
            if (!arrow) {
                return;
            }
            const sorted = index === column;
            th.setAttribute('aria-sort', sorted ? (this._ascending ? 'ascending' : 'descending') : 'none');
            arrow.classList.toggle('fa-sort', !sorted);
            arrow.classList.toggle('fa-sort-up', sorted && this._ascending);
            arrow.classList.toggle('fa-sort-down', sorted && !this._ascending);
            arrow.classList.toggle('text-muted', !sorted);
        });
    }

    /**
     * The value a cell sorts by: a number, a non-empty string, or null when empty.
     */
    _value(cell) {
        if (!cell) {
            return null;
        }
        const raw = (cell.dataset.sortValue ?? cell.textContent).trim();
        if (raw === '') {
            return null;
        }
        const number = Number(raw);

        return Number.isFinite(number) ? number : raw;
    }
}
