/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2022 Jan Böhmer (https://github.com/jbtronics)
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
 * Keeps at least one kind of part selected, as showing neither would always give an empty list.
 */
export default class extends Controller {

    connect() {
        //A link naming only other filters arrives with no kind set, which shows all parts
        if (!this._boxes().some((box) => box.checked)) {
            this._boxes().forEach((box) => box.checked = true);
        }
    }

    /**
     * When the last selected kind is deselected, the other one takes over.
     */
    update(event) {
        if (!this._boxes().some((box) => box.checked)) {
            this._boxes().filter((box) => box !== event.target).forEach((box) => box.checked = true);
        }
    }

    _boxes() {
        return [...this.element.querySelectorAll('input[type="checkbox"]')];
    }
}
