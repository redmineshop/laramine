import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type WikiRenameProps = {
    project: string;
    title: string;
    slug: string;
};

export default function WikiRename({ project, title, slug }: WikiRenameProps) {
    const form = useForm({ title, redirect: true });

    function submit(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        form.transform((data) => ({ title: data.title, redirect: data.redirect ? '1' : '0' }));
        form.post(`/projects/${project}/wiki/${slug}/rename`);
    }

    return (
        <>
            <Head title={`Rename ${title}`} />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>Rename {title}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form className="flex flex-col gap-3" onSubmit={submit}>
                            <Label htmlFor="title">Title</Label>
                            <Input id="title" value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} />
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.redirect}
                                    onChange={(event) => form.setData('redirect', event.target.checked)}
                                />
                                Redirect the old title
                            </label>
                            <Button type="submit" disabled={form.processing}>
                                Rename
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
