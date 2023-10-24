import java.util.Scanner;

public class TicTacToe {
    private static char[][] board = new char[3][3];
    private static char currentPlayer = 'X';

    public static void main(String[] args) {
        initializeBoard();
        boolean gameEnded = false;

        while (!gameEnded) {
            displayBoard();
            int[] move = getPlayerMove();
            int row = move[0];
            int col = move[1];

            if (isValidMove(row, col)) {
                makeMove(row, col);
                gameEnded = isGameFinished(row, col);
                currentPlayer = (currentPlayer == 'X') ? 'O' : 'X';
            } else {
                System.out.println("Invalid move. Please try again.");
            }
        }

        displayBoard();
        char winner = getWinner();
        if (winner == ' ')
            System.out.println("It's a draw!");
        else
            System.out.println("Player " + winner + " wins!");
    }

    public static void initializeBoard() {
        for (int i = 0; i < 3; i++) {
            for (int j = 0; j < 3; j++) {
                board[i][j] = ' ';
            }
        }
    }

    public static void displayBoard() {
        System.out.println("-------------");
        for (int i = 0; i < 3; i++) {
            System.out.print("| ");
            for (int j = 0; j < 3; j++) {
                System.out.print(board[i][j] + " | ");
            }
            System.out.println("\n-------------");
        }
    }

    public static int[] getPlayerMove() {
        Scanner scanner = new Scanner(System.in);
        int[] move = new int[2];
        System.out.print("Player " + currentPlayer + ", enter row (0-2): ");
        move[0] = scanner.nextInt();
        System.out.print("Player " + currentPlayer + ", enter column (0-2): ");
        move[1] = scanner.nextInt();
        return move;
    }

    public static boolean isValidMove(int row, int col) {
        return (row >= 0 && row < 3 && col >= 0 && col < 3 && board[row][col] == ' ');
    }

    public static void makeMove(int row, int col) {
        board[row][col] = currentPlayer;
    }

    public static boolean isGameFinished(int row, int col) {
        return (checkRows(row) || checkColumns(col) || checkDiagonals() || checkBoardFull());
    }

    public static boolean checkRows(int row) {
        return (board[row][0] == currentPlayer && board[row][1] == currentPlayer && board[row][2] == currentPlayer);
    }

    public static boolean checkColumns(int col) {
        return (board[0][col] == currentPlayer && board[1][col] == currentPlayer && board[2][col] == currentPlayer);
    }

    public static boolean checkDiagonals() {
        return ((board[0][0] == currentPlayer && board[1][1] == currentPlayer && board[2][2] == currentPlayer) ||
                (board[0][2] == currentPlayer && board[1][1] == currentPlayer && board[2][0] == currentPlayer));
    }

    public static boolean checkBoardFull() {
        for (int i = 0; i < 3; i++) {
            for (int j = 0; j < 3; j++) {
                if (board[i][j] == ' ')
                    return false;
            }
        }
        return true;
    }

    public static char getWinner() {
        if (checkRows(0) || checkColumns(0) || checkDiagonals())
            return currentPlayer;
        else if (checkRows(1) || checkColumns(1))
            return currentPlayer;
        else if (checkRows(2) || checkColumns(2))
            return currentPlayer;
        return ' ';
    }
}
