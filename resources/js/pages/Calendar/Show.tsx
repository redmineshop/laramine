import { Head } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

type CalendarEvent = {
    kind: string;
    id: number;
};

type CalendarDay = {
    date: string;
    in_month: boolean;
    non_working: boolean;
    week: number;
    events: CalendarEvent[];
};

type CalendarShowProps = {
    projectId: number | null;
    year: number;
    month: number;
    firstWday: number;
    days: CalendarDay[];
};

export default function CalendarShow({ projectId, year, month, firstWday, days }: CalendarShowProps) {
    return (
        <>
            <Head title="Calendar" />
            <main className="mx-auto flex min-h-svh max-w-5xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>
                            Calendar {year}-{month}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">
                            Minimal month grid. This is not a Redmine screen and not a 0.1 release.
                            {projectId === null ? ' Cross-project.' : ` Project ${projectId}.`} Week starts on day{' '}
                            {firstWday}.
                        </p>
                        <ul className="grid grid-cols-1 gap-2 sm:grid-cols-7">
                            {days.map((day) => (
                                <li key={day.date} className="rounded-md border border-border p-2 text-sm">
                                    <p>
                                        {day.date}
                                        {day.non_working ? ' · off' : ''}
                                    </p>
                                    {day.events.length === 0 ? (
                                        <p className="text-muted-foreground">No events</p>
                                    ) : (
                                        <ul>
                                            {day.events.map((event) => (
                                                <li key={`${event.kind}-${event.id}`}>
                                                    {event.kind} {event.id}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
