import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

function namedError(errors: object, name: string): string | null {
    if (!(name in errors)) {
        return null;
    }
    const value: unknown = Reflect.get(errors, name);

    return typeof value === 'string' ? value : null;
}

type BoardOption = {
    id: number;
    name: string;
};

type BoardForm = {
    id: number;
    name: string;
    description: string;
    parent_id: number | null;
    position: number | null;
};

type BoardsEditProps = {
    mode: 'new' | 'edit';
    project: string;
    board: BoardForm | null;
    boards: BoardOption[];
};

export default function BoardsEdit({ mode, project, board, boards }: BoardsEditProps) {
    const form = useForm({
        name: board?.name ?? '',
        description: board?.description ?? '',
        parent_id: board?.parent_id === null || board?.parent_id === undefined ? '' : String(board.parent_id),
        position: board?.position === null || board?.position === undefined ? '' : String(board.position),
    });

    function submit(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        form.transform((data) => ({
            name: data.name,
            description: data.description,
            parent_id: data.parent_id === '' ? null : Number(data.parent_id),
            position: data.position === '' ? null : Number(data.position),
        }));
        if (mode === 'new') {
            form.post(`/projects/${project}/boards`);

            return;
        }
        if (board !== null) {
            form.put(`/projects/${project}/boards/${board.id}`);
        }
    }

    return (
        <>
            <Head title={mode === 'new' ? 'New board' : 'Edit board'} />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>{mode === 'new' ? 'New board' : `Edit ${board?.name ?? 'board'}`}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form className="flex flex-col gap-3" onSubmit={submit}>
                            <p className="text-sm text-muted-foreground">
                                Functional board form. This is not a Redmine screen and not a 0.1 release.
                            </p>
                            <Label htmlFor="name">Name</Label>
                            <Input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                            <Label htmlFor="description">Description</Label>
                            <textarea
                                id="description"
                                className="border-input min-h-24 w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                                value={form.data.description}
                                onChange={(event) => form.setData('description', event.target.value)}
                            />
                            <Label htmlFor="parent_id">Parent</Label>
                            <select
                                id="parent_id"
                                className="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                                value={form.data.parent_id}
                                onChange={(event) => form.setData('parent_id', event.target.value)}
                            >
                                <option value="">None</option>
                                {boards.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.name}
                                    </option>
                                ))}
                            </select>
                            <Label htmlFor="position">Position</Label>
                            <Input
                                id="position"
                                value={form.data.position}
                                onChange={(event) => form.setData('position', event.target.value)}
                            />
                            <Button type="submit" disabled={form.processing}>
                                Save
                            </Button>
                            {namedError(form.errors, 'form') === null ? null : (
                                <p className="text-sm text-destructive">{namedError(form.errors, 'form')}</p>
                            )}
                        </form>
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
