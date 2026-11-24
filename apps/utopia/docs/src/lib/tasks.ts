import board from '../../content/tasks.json'

export type TaskStatus = 'todo' | 'progress' | 'blocked' | 'done'

export type Task = {
  id: string
  title: string
  area: string
  status: TaskStatus
  note: string
  updated: string
}

export type Board = { updated: string; tasks: Task[] }

/** The migration's task board (content/tasks.json), kept by hand as work moves. */
export const BOARD = board as Board

/** The board's columns, left to right. */
export const COLUMNS: { status: TaskStatus; label: string; note: string; dot: string }[] = [
  { status: 'todo', label: 'Up next', note: 'Planned, not started.', dot: 'bg-planned' },
  { status: 'progress', label: 'In progress', note: 'Being worked on now.', dot: 'bg-started' },
  { status: 'blocked', label: 'Waiting', note: 'Ready to go once what it waits on lands.', dot: 'bg-progress' },
  { status: 'done', label: 'Done', note: 'Finished and verified.', dot: 'bg-ok' },
]
