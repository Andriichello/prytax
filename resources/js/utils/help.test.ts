import { describe, expect, it } from 'vitest';
import { helpTopics, type HelpTopic, searchTopics } from '@/utils/help';

const TOPICS: HelpTopic[] = [
    {
        key: 'invoices',
        title: 'Інвойси',
        lead: 'Інвойс створюєте в PryTax або завантажуєте готовий PDF.',
        questions: [
            { key: 'create', title: 'Створити інвойс', text: 'Заповніть групи й додайте позиції.' },
            { key: 'freeze', title: 'Зафіксувати й надіслати', text: 'Номер присвоюється під час фіксації.' },
        ],
    },
    {
        key: 'taxes',
        title: 'Податки',
        lead: 'Один реєстр за обраний період.',
        questions: [
            { key: 'payEsv', title: 'Сплатити ЄСВ', text: 'Відкрийте рядок ЄСВ потрібного місяця.' },
            { key: 'attach', title: 'Прив’язати сплату', text: 'Сплата належить надходженню.' },
        ],
    },
];

describe('searchTopics', () => {
    it('finds every topic, its questions closed, without a query', () => {
        expect(searchTopics(TOPICS, '  ').map((topic) => [topic.key, topic.questions.length, topic.opened])).toEqual([
            ['invoices', 2, []],
            ['taxes', 2, []],
        ]);
    });

    it('keeps only the questions, which have every word, and opens them', () => {
        const found = searchTopics(TOPICS, 'сплатити єсв');

        expect(found.map((topic) => [topic.key, topic.questions.map((question) => question.key), topic.opened])).toEqual([
            ['taxes', ['payEsv'], ['payEsv']],
        ]);
    });

    it('finds a question by the words of its topic and of its own together', () => {
        expect(searchTopics(TOPICS, 'податки місяця').map((topic) => topic.opened)).toEqual([['payEsv']]);
    });

    it('shows every question of a topic, which its title or lead matches, opening those which match themselves', () => {
        expect(searchTopics(TOPICS, 'PDF').map((topic) => [topic.key, topic.questions.length, topic.opened])).toEqual([['invoices', 2, []]]);
        expect(searchTopics(TOPICS, 'інвойс').map((topic) => [topic.key, topic.questions.length, topic.opened])).toEqual([['invoices', 2, ['create']]]);
    });

    it('takes the apostrophes ’ and \' for one another, in any case', () => {
        expect(searchTopics(TOPICS, 'ПРИВ\'ЯЗАТИ').map((topic) => topic.opened)).toEqual([['attach']]);
    });

    it('finds nothing, when a word is nowhere', () => {
        expect(searchTopics(TOPICS, 'сплатити декларацію')).toEqual([]);
    });
});

describe('helpTopics', () => {
    const keys = (topics: HelpTopic[]): string[] => topics.map((topic) => topic.key);

    it('leaves the backups out for those who don\'t see them', () => {
        expect(keys(helpTopics({ settings: 'Ще', backups: false, desktopApp: true }))).not.toContain('backups');
        expect(keys(helpTopics({ settings: 'Ще', backups: true, desktopApp: false }))).toContain('backups');
    });

    it('tells of the recovery kit in the desktop app only', () => {
        const backupQuestions = (desktopApp: boolean): string[] => helpTopics({ settings: 'Ще', backups: true, desktopApp })
            .find((topic) => topic.key === 'backups')!.questions.map((question) => question.key);

        expect(backupQuestions(true)).toContain('recoveryKit');
        expect(backupQuestions(false)).not.toContain('recoveryKit');
    });

    it('names the settings page where the reader is', () => {
        const record = helpTopics({ settings: 'Ще', backups: false, desktopApp: false })
            .find((topic) => topic.key === 'transfers')!.questions.find((question) => question.key === 'record')!;

        expect(record.text).toMatch(/^«Ще → Перекази та комісії»/);
    });
});
