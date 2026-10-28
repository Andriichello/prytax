import type { Messages } from '@/i18n/types';

/**
 * Ukrainian texts of the app's frame: start-up, sign-in and passwords, navigation, Ще, notifications, the quarter's
 * summary and the shared components.
 */
export default {
    /** The app's frame, when it can't start */
    startup: {
        title: 'Не вдалося відкрити PryTax',
        tryAgain: 'Спробувати знову',
    },

    /** Вхід */
    login: {
        email: 'Електронна пошта',
        password: 'Пароль',
        rememberMe: 'Запам’ятати мене',
        forgotPassword: 'Забули пароль?',
        signIn: 'Увійти',
        accessNote: 'Доступ надає власник організації.',
        accountantNote: 'Бухгалтер бачить усі ФОП своєї практики.',
    },

    /** Забули пароль? */
    forgotPassword: {
        checkEmail: 'Перевірте пошту',
        sent:
            'Якщо {email} належить обліковому запису, ми надіслали на неї посилання для зміни пароля. Воно діє годину.',
        backToSignIn: 'Повернутися до входу',
        title: 'Зміна пароля',
        hint: 'Надішлемо посилання для нового пароля на вашу пошту.',
        sendLink: 'Надіслати посилання',
    },

    /** The e-mailed reset link */
    resetPassword: {
        passwordChanged: 'Пароль змінено',
        changedHint: 'Увійдіть з новим паролем. Інші сесії цього облікового запису завершено.',
        linkIncomplete: 'Посилання неповне',
        openWholeLink: 'Відкрийте посилання з листа повністю або попросіть нове.',
        sendNewLink: 'Надіслати нове посилання',
        newPassword: 'Новий пароль',
        forEmail: 'для {email}',
        confirmPassword: 'Повторіть пароль',
        savePassword: 'Зберегти пароль',
    },

    /** The e-mailed invitation */
    invitation: {
        invitationAccepted: 'Запрошення прийнято',
        acceptedHint: 'Увійдіть зі своєю поштою і щойно заданим паролем.',
        openWholeLink: 'Відкрийте посилання з листа повністю або попросіть власника організації запросити вас знову.',
        title: 'Вас запрошено',
        hint: 'Задайте пароль, щоб приєднатися до організації.',
        join: 'Приєднатися',
    },

    /** A password set by someone else */
    changePassword: {
        title: 'Задайте свій пароль',
        explained: 'Пароль для {email} задав не ви, тож перед роботою його треба змінити.',
        currentPassword: 'Поточний пароль',
        confirmNewPassword: 'Повторіть новий пароль',
    },

    /** No ФОП yet, and a page that doesn't exist */
    noProfile: {
        noFopsYet: 'Ще немає жодного ФОП',
        explained: 'Власник організації має додати ФОП, перш ніж {name} зможете працювати з обліком.',
        you: 'ви',
        pageNotFound: 'Сторінку не знайдено',
        notFoundHint: 'Можливо, посилання застаріло або в ньому помилка.',
        goHome: 'На головну',
    },

    /** The tab bar and the headers */
    nav: {
        home: 'Головна',
        invoices: 'Інвойси',
        accounting: 'Облік',
        taxes: 'Податки',
        mainNavigation: 'Основна навігація',
        back: 'Назад',
        fopGroup: 'ФОП · {group} група',
        notificationsUnread: 'Сповіщення, непрочитаних: {count}',
        books: 'Книги обліку',
        barActions: 'Книги обліку й сповіщення',
        booksShort: 'Книги',
        settings: 'Налаштування',
        profileDetails: 'Профіль і реквізити',
        sidebar: 'Бічне меню',
        recordPanel: 'Запис',
    },

    /** ФОП організації: switching the ФОП, and Ще's «ФОП» card */
    profiles: {
        title: 'ФОП організації',
        fop: 'ФОП',
        change: 'Змінити ФОП',
        inOrganization: '{count} в організації',
        search: 'Пошук за ПІБ або РНОКПП',
        searchLabel: 'Пошук ФОП',
        active: 'Активні',
        inactive: 'Неактивні',
        current: 'Поточний ФОП',
        taxIdMasked: 'РНОКПП {masked}',
        passportMasked: 'Паспорт {masked}',
        fopLine: 'ФОП · {group} група · {taxId}',
        noAlerts: 'без сповіщень',
        taxesToPay: '{amount} ₴ податків до сплати',
        taxesPaid: 'податки сплачено',
        esvDue: 'ЄСВ до {date}',
        closedOn: 'Неактивний · закрито {date}',
        inactiveShort: 'Неактивний',
        nothingFound: 'Нікого не знайдено',
        footnote: 'Перемикання змінює ФОП для всього застосунку: інвойси, облік, податки й налаштування. Користувачі й ролі — спільні для організації. Неактивного ФОП можна відкрити, щоб переглянути історію.',
        newProfile: 'Новий ФОП',
    },

    /** Ще and the language switch */
    more: {
        tagline: 'Облік доходів, інвойси та податки для ФОП 3 групи',
        more: 'Ще',
        moneyCounterparties: 'Гроші й контрагенти',
        transfersFees: 'Перекази та комісії',
        feesNetIncome: 'Комісії й чистий дохід',
        clientsContracts: 'Клієнти й договори',
        fopSettings: 'Налаштування ФОП',
        fopProfileDetails: 'Профіль і реквізити ФОП',
        organization: 'Організація',
        usersRoles: 'Користувачі й ролі',
        signOut: 'Вийти',
        appLanguage: 'Мова застосунку',
        appLanguageNote: 'Лише інтерфейс і лише для вас. Мова інвойсів — у «Документ інвойсу» та в картці клієнта.',
        appLanguageGuestNote: 'Запам’ятаємо на цьому пристрої. Після входу мову можна змінити в «Ще › Мова застосунку».',
        appLanguageShortNote: 'Лише інтерфейс. Мова інвойсів — у «Документ інвойсу» та в картці клієнта.',
    },

    /** Сповіщення */
    alerts: {
        notifications: 'Сповіщення',
        notificationSettings: 'Налаштування сповіщень',
        all: 'Усі',
        unreadFilter: 'Непрочитані',
        readHint: 'Сповіщення стає прочитаним, коли ви натискаєте на нього, на його дію або на ✓.',
        noUnreadNotifications: 'Непрочитаних сповіщень немає.',
        noNotifications: 'Сповіщень немає: усе гаразд.',
        showMore: 'Показати ще',
        unread: 'Непрочитане',
        read: 'Прочитано',
        markRead: 'Позначити прочитаним',
    },

    /** Огляд практики */
    overview: {
        incomeCapFor: 'Ліміт доходу {year}',
        ofTotal: 'з {total}',
        incomeIn: 'Дохід {quarter}',
        noInvoicesQuarter: 'Інвойсів за квартал немає.',
        taxableIncome: 'Дохід для податків: {amount}',
        taxesIn: 'Податки {quarter}',
        paidAmount: 'сплачено {amount}',
        sentAmount: 'надіслано {amount}',
        receivedOf: 'отримано {received} з {invoiced}',
    },

    /** «Фільтри» of Облік and Податки (design.md §16.2) */
    filters: {
        title: 'Фільтри',
        reset: 'Скинути',
        apply: 'Застосувати',
        period: 'Період',
        oneQuarter: 'Один рік і один квартал.',
        grouping: 'Групувати',
        wholeYearBadge: '{year} · увесь рік',
        wholeYearHint: 'Увесь рік. Натисніть квартал, щоб обрати лише його.',
        rangeHint: 'Обрано {range}. Інший квартал розширює діапазон; обраний — прибирає його. Прибрати єдиний — увесь рік.',
    },

    /** Sheets, loading and failures, copying a requisite, stepping through periods */
    ui: {
        close: 'Закрити',
        loadingLabel: 'Завантаження',
        loadMoreFailed: 'Не вдалося завантажити далі.',
        notFound: 'Не знайдено: запис видалено, або він належить іншому ФОП.',
        forbidden: 'Недостатньо прав, щоб це переглянути.',
        loadFailed: 'Не вдалося завантажити дані. Перевірте з’єднання і спробуйте ще раз.',
        tryAgain: 'Спробувати ще раз',
        copied: 'Скопійовано',
        copyLabel: 'Копіювати: {label}',
        previous: 'Попередній',
        next: 'Наступний',
    },

    /** A date field */
    dateField: {
        placeholder: 'дд.мм.рррр',
        invalid: 'Дата у форматі дд.мм.рррр',
        notBefore: 'Не раніше {date}',
        notAfter: 'Не пізніше {date}',
        today: 'Сьогодні',
        chooseMonth: 'Обрати місяць і рік',
        previousMonth: 'Попередній місяць',
        nextMonth: 'Наступний місяць',
        previousYear: 'Попередній рік',
        nextYear: 'Наступний рік',
        yesterday: 'Вчора',
        done: 'Готово',
        period: 'Період',
        periodDays: '{from} – {to} · {days} дн.',
        chooseEnd: 'Оберіть кінець періоду',
        rangeHint: 'Торкніться початку, потім кінця. Третій дотик починає новий період.',
        futureUnavailable: 'Майбутні дати недоступні — кошти ще не зараховано.',
        from: 'з',
        to: 'по',
        presets: {
            thisMonth: 'Цей місяць',
            nextMonth: 'Наступний місяць',
            lastMonth: 'Минулий місяць',
            thisQuarter: 'Цей квартал',
        },
    },
} satisfies Messages;
