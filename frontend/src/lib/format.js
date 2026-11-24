// Мелкие функции форматирования для отображения данных.

/** Типы публикаций: значение в API → подпись для пользователя */
export const publicationTypes = [
	{ value: 'question', label: 'Вопрос' },
	{ value: 'discussion', label: 'Обсуждение' },
	{ value: 'announcement', label: 'Объявление' }
];

/**
 * Название типа публикации по-русски.
 *
 * @param {string} type Значение `question`, `discussion` или `announcement`
 * @returns {string}
 */
export function typeLabel(type) {
	return publicationTypes.find((item) => item.value === type)?.label ?? type;
}

/**
 * Дата и время в местном часовом поясе, например `24.11.2026 11:15`.
 *
 * @param {string} iso Дата в формате ISO 8601
 * @returns {string}
 */
export function formatDate(iso) {
	return new Date(iso)
		.toLocaleString('ru-RU', {
			day: '2-digit',
			month: '2-digit',
			year: 'numeric',
			hour: '2-digit',
			minute: '2-digit'
		})
		.replace(', ', ' ');
}

/**
 * Выбирает форму слова по числу: 1 символ, 2 символа, 5 символов.
 *
 * @param {number} count Число
 * @param {[string, string, string]} forms Формы для 1, 2 и 5
 * @returns {string}
 */
export function pluralize(count, forms) {
	const lastTwo = count % 100;
	const last = count % 10;
	if (lastTwo >= 11 && lastTwo <= 14) return forms[2];
	if (last === 1) return forms[0];
	if (last >= 2 && last <= 4) return forms[1];
	return forms[2];
}

/**
 * Опубликовано ли меньше 24 часов назад (для метки «NEW!»).
 *
 * @param {string} iso Дата в формате ISO 8601
 * @returns {boolean}
 */
export function isNew(iso) {
	return Date.now() - new Date(iso).getTime() < 24 * 60 * 60 * 1000;
}

/**
 * Число с нулями слева для «счётчика посещений», например `000123`.
 *
 * @param {number} value Число
 * @param {number} [digits] Сколько цифр показывать
 * @returns {string}
 */
export function padCounter(value, digits = 6) {
	return String(value).padStart(digits, '0');
}
