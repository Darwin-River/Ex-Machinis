-- Loans (#928), part 7 of the revised system (#921). A public company's
-- code asks for a loan (request_loan(amount, rate, due_in)); players with
-- at least forge.lend_min_credits back it from the website or the API, and
-- each backing is lent to the company at once. On the due date the company
-- repays each lender what they lent plus the interest; it can repay early
-- (repay(id)), paying the interest due so far. If it can't repay in full,
-- every credit it receives goes to its lenders, in proportion to what each
-- is owed, until they are paid, and its dividends and payouts stop.
--
-- loan_requests: what the code asked for. loans: each player's backing of a
-- request. loan_actions: cancel_request and repay from the code, which the
-- website's tick settles (as pay_out's company_payouts).

CREATE TABLE IF NOT EXISTS loan_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    drone INT UNSIGNED NOT NULL COMMENT 'The drone whose code asked',
    amount BIGINT NOT NULL COMMENT 'Credits asked for',
    rate_bp INT NOT NULL COMMENT 'Simple interest for the whole loan, in hundredths of a percent',
    requested_t BIGINT NOT NULL COMMENT 'Game time',
    due_t BIGINT NOT NULL COMMENT 'Game time it is due',
    backed BIGINT NOT NULL DEFAULT 0 COMMENT 'Credits lent so far',
    cancelled_t BIGINT NULL COMMENT 'Game time its unbacked part was withdrawn',
    KEY loan_requests_company (company_id),
    KEY loan_requests_open (cancelled_t, due_t)
) ENGINE = InnoDB COMMENT 'Loans a public company''s code asked for (#928)';

CREATE TABLE IF NOT EXISTS loans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL COMMENT 'The lender',
    principal BIGINT NOT NULL,
    rate_bp INT NOT NULL,
    lent_t BIGINT NOT NULL COMMENT 'Game time',
    due_t BIGINT NOT NULL COMMENT 'Game time',
    owed BIGINT NOT NULL COMMENT 'What it owes now: principal plus interest, less what it has repaid; set at the due date or an early repayment',
    repaid BIGINT NOT NULL DEFAULT 0 COMMENT 'Credits repaid to the lender so far',
    closed_t BIGINT NULL COMMENT 'Game time it was repaid in full (or the company ended)',
    defaulted_t BIGINT NULL COMMENT 'Game time it was due and not repaid in full',
    KEY loans_company (company_id, closed_t),
    KEY loans_user (user_id, closed_t),
    KEY loans_request (request_id)
) ENGINE = InnoDB COMMENT 'Players'' loans to companies (#928)';

CREATE TABLE IF NOT EXISTS loan_actions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    drone INT UNSIGNED NOT NULL,
    kind VARCHAR(16) NOT NULL COMMENT 'cancel or repay',
    request_id INT UNSIGNED NOT NULL,
    requested_t BIGINT NOT NULL,
    done_t BIGINT NULL,
    outcome VARCHAR(32) NULL,
    KEY loan_actions_due (done_t, id)
) ENGINE = InnoDB COMMENT 'cancel_request and repay from a company''s code, settled by the website (#928)';
