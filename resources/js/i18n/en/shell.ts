/**
 * English texts of sign-in, navigation, notifications, the practice overview, the ФОП switcher and the shared
 * components, keyed by their Ukrainian source (see `i18n/index.ts`).
 */
export default {
    // The app's frame, when it can't start
    'Не вдалося відкрити PryTax': 'Couldn’t open PryTax',
    'Спробувати знову': 'Try again',

    // Вхід
    'Електронна пошта': 'Email',
    'Пароль': 'Password',
    'Запам’ятати мене': 'Remember me',
    'Забули пароль?': 'Forgot your password?',
    'Увійти': 'Sign in',
    'Доступ надає власник організації.': 'Access is given by the organization’s owner.',
    'Бухгалтер бачить усі ФОП своєї практики.': 'An accountant sees every FOP of their practice.',

    // Забули пароль?
    'Перевірте пошту': 'Check your email',
    'Якщо {email} належить обліковому запису, ми надіслали на неї посилання для зміни пароля. Воно діє годину.': 'If {email} belongs to an account, we’ve sent it a link to change the password. The link is valid for an hour.',
    'Повернутися до входу': 'Back to sign-in',
    'Зміна пароля': 'Reset your password',
    'Надішлемо посилання для нового пароля на вашу пошту.': 'We’ll email you a link to set a new password.',
    'Надіслати посилання': 'Send link',

    // The e-mailed reset link
    'Пароль змінено': 'Password changed',
    'Увійдіть з новим паролем. Інші сесії цього облікового запису завершено.': 'Sign in with your new password. The account’s other sessions have been ended.',
    'Посилання неповне': 'The link is incomplete',
    'Відкрийте посилання з листа повністю або попросіть нове.': 'Open the whole link from the email, or ask for a new one.',
    'Надіслати нове посилання': 'Send a new link',
    'Новий пароль': 'New password',
    'для {email}': 'for {email}',
    'Повторіть пароль': 'Confirm password',
    'Зберегти пароль': 'Save password',

    // The e-mailed invitation
    'Запрошення прийнято': 'Invitation accepted',
    'Увійдіть зі своєю поштою і щойно заданим паролем.': 'Sign in with your email and the password you’ve just set.',
    'Відкрийте посилання з листа повністю або попросіть власника організації запросити вас знову.': 'Open the whole link from the email, or ask the organization’s owner to invite you again.',
    'Вас запрошено': 'You’re invited',
    'Задайте пароль, щоб приєднатися до організації.': 'Set a password to join the organization.',
    'Приєднатися': 'Join',

    // A password set by someone else
    'Задайте свій пароль': 'Set your own password',
    'Пароль для {email} задав не ви, тож перед роботою його треба змінити.': 'The password for {email} was set by someone else, so change it before you start.',
    'Поточний пароль': 'Current password',
    'Повторіть новий пароль': 'Confirm new password',

    // No ФОП yet, and a page that doesn't exist
    'Ще немає жодного ФОП': 'No FOPs yet',
    'Власник організації має додати ФОП, перш ніж {name} зможете працювати з обліком.': 'The organization’s owner needs to add a FOP before {name} can work with the records.',
    'ви': 'you',
    'Сторінку не знайдено': 'Page not found',
    'Можливо, посилання застаріло або в ньому помилка.': 'The link may be outdated or contain a mistake.',
    'На головну': 'Go to Home',

    // The tab bar and the headers
    'Головна': 'Home',
    'Інвойси': 'Invoices',
    'Облік': 'Accounting',
    'Податки': 'Taxes',
    'Основна навігація': 'Main navigation',
    'Назад': 'Back',
    '{name}, змінити ФОП': '{name}, switch FOP',
    'Оберіть ФОП': 'Choose a FOP',
    'ФОП · {group} група': 'FOP · group {group}',

    // The ФОП switcher
    'Пошук ФОП': 'Search FOPs',
    'Пошук за ПІБ або РНОКПП': 'Search by name or tax ID',
    'Неактивний': 'Inactive',
    'Неактивний · закрито {date}': 'Inactive · closed {date}',
    'Потрібна уточнююча декларація': 'Corrective declaration needed',
    'РНОКПП {taxId} · {alerts}': 'Tax ID {taxId} · {alerts}',
    'без сповіщень': 'no notifications',
    'Нікого не знайдено.': 'Nothing found.',
    'Огляд усіх': 'Overview of all',
    'Додати ФОП': 'Add a FOP',

    // Сповіщення
    'Сповіщення': 'Notifications',
    'Налаштування сповіщень': 'Notification settings',
    'Усі': 'All',
    'Непрочитані': 'Unread',
    'Кошти': 'Funds',
    'Терміни': 'Deadlines',
    'Сповіщення стає прочитаним, коли ви натискаєте на нього, на його дію або на ✓.': 'A notification is marked as read when you tap it, its action or ✓.',
    'Непрочитаних сповіщень немає.': 'No unread notifications.',
    'Сповіщень немає: усе гаразд.': 'No notifications: all is well.',
    'Показати ще': 'Show more',
    'Непрочитане': 'Unread',
    'Прочитано': 'Read',
    'Позначити прочитаним': 'Mark as read',

    // Огляд практики
    'Практика · {count}': 'Practice · {count}',
    'Огляд практики': 'Practice overview',
    '{quarter} квартал {year}': 'Q{quarter} {year}',
    'Попередній квартал': 'Previous quarter',
    'Наступний квартал': 'Next quarter',
    'У цьому кварталі немає активних ФОП.': 'No active FOPs in this quarter.',
    'Показати неактивні ({count})': 'Show inactive ({count})',
    'Ліміт доходу {year}': 'Income cap {year}',
    'з {total}': 'of {total}',
    'Дохід {quarter}': 'Income {quarter}',
    'отримано {received} з {invoiced} · {percent}': 'received {received} of {invoiced} · {percent}',
    'Інвойсів за квартал немає.': 'No invoices this quarter.',
    'Дохід для податків: {amount}': 'Taxable income: {amount}',
    'Податки {quarter}': 'Taxes {quarter}',
    'сплачено {amount}': 'paid {amount}',
    'надіслано {amount}': 'sent {amount}',
    'Немає сповіщень': 'No notifications',

    // Sheets, loading and failures
    'Закрити': 'Close',
    'Скасувати': 'Cancel',
    'Завантаження…': 'Loading…',
    'Завантаження': 'Loading',
    'Не вдалося завантажити далі.': 'Couldn’t load more.',
    'Повторити': 'Retry',
    'Не знайдено: запис видалено, або він належить іншому ФОП.': 'Not found: the record was deleted or belongs to another FOP.',
    'Недостатньо прав, щоб це переглянути.': 'You don’t have permission to view this.',
    'Не вдалося завантажити дані. Перевірте з’єднання і спробуйте ще раз.': 'Couldn’t load the data. Check your connection and try again.',
    'Спробувати ще раз': 'Try again',

    // Copying a requisite, stepping through periods
    'Скопійовано': 'Copied',
    'Копіювати: {label}': 'Copy {label}',
    'Попередній': 'Previous',
    'Наступний': 'Next',

    // A date field
    'дд.мм.рррр': 'dd.mm.yyyy',
    'Дата у форматі дд.мм.рррр': 'Enter the date as dd.mm.yyyy',
    'Не раніше {date}': 'No earlier than {date}',
    'Не пізніше {date}': 'No later than {date}',
    '{label}: календар': '{label}: calendar',
} satisfies Record<string, string>;
