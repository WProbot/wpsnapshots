<?php
/**
 * Push command
 *
 * @package wpsnapshots
 */

namespace WPSnapshots\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use WPSnapshots\RepositoryManager;
use WPSnapshots\WordPressBridge;
use WPSnapshots\Config;
use WPSnapshots\Utils;
use WPSnapshots\Snapshot;
use WPSnapshots\Log;

/**
 * The push command first runs "create" to create the snapshot, then pushes it to a remote repository.
 */
class Push extends Command {

	/**
	 * Setup up command
	 */
	protected function configure() {
		$this->setName( 'push' );
		$this->setDescription( 'Push a snapshot to a repository.' );
		$this->addArgument( 'snapshot_id', InputArgument::OPTIONAL, 'Optional snapshot ID to push. If none is provided, a new snapshot will be created from the local environment.' );
		$this->addOption( 'repository', null, InputOption::VALUE_REQUIRED, 'Repository to use. Defaults to first repository saved in config.' );
		$this->addOption( 'no_scrub', false, InputOption::VALUE_NONE, "Don't scrub personal user data." );
		$this->addOption( 'small', false, InputOption::VALUE_NONE, 'Trim data and files to create a small snapshot. Note that this action will modify your local.' );

		$this->addOption( 'path', null, InputOption::VALUE_REQUIRED, 'Path to WordPress files.' );
		$this->addOption( 'db_host', null, InputOption::VALUE_REQUIRED, 'Database host.' );
		$this->addOption( 'db_name', null, InputOption::VALUE_REQUIRED, 'Database name.' );
		$this->addOption( 'db_user', null, InputOption::VALUE_REQUIRED, 'Database user.' );
		$this->addOption( 'db_password', null, InputOption::VALUE_REQUIRED, 'Database password.' );
		$this->addOption( 'exclude', false, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude a file or directory from the snapshot.' );
		$this->addOption( 'exclude_uploads', false, InputOption::VALUE_NONE, 'Exclude uploads from pushed snapshot.' );
	}

	/**
	 * Executes the command
	 *
	 * @param  InputInterface  $input Command input
	 * @param  OutputInterface $output Command output
	 */
	protected function execute( InputInterface $input, OutputInterface $output ) {
		Log::instance()->setOutput( $output );

		$snapshot_id = $input->getArgument( 'snapshot_id' );

		if ( empty( $snapshot_id ) ) {
			$repository = RepositoryManager::instance()->setup( $input->getOption( 'repository' ) );

			if ( ! $repository ) {
				Log::instance()->write( 'Could not setup repository.', 0, 'error' );
				return 1;
			}

			$path = $input->getOption( 'path' );

			if ( empty( $path ) ) {
				$path = getcwd();
			}

			$path = Utils\normalize_path( $path );

			$helper = $this->getHelper( 'question' );

			$verbose = $input->getOption( 'verbose' );

			$project_question = new Question( 'Project Slug (letters, numbers, _, and - only): ' );
			$project_question->setValidator( '\WPSnapshots\Utils\slug_validator' );

			$project = $helper->ask( $input, $output, $project_question );

			$description_question = new Question( 'Snapshot Description (e.g. Local environment): ' );
			$description_question->setValidator( '\WPSnapshots\Utils\not_empty_validator' );

			$description = $helper->ask( $input, $output, $description_question );

			$exclude = $input->getOption( 'exclude' );

			if ( ! empty( $input->getOption( 'exclude_uploads' ) ) ) {
				$exclude[] = './uploads';
			}

			$snapshot = Snapshot::create(
				[
					'path'        => $path,
					'db_host'     => $input->getOption( 'db_host' ),
					'db_name'     => $input->getOption( 'db_name' ),
					'db_user'     => $input->getOption( 'db_user' ),
					'db_password' => $input->getOption( 'db_password' ),
					'project'     => $project,
					'description' => $description,
					'no_scrub'    => $input->getOption( 'no_scrub' ),
					'small'       => $input->getOption( 'small' ),
					'exclude'     => $exclude,
					'repository'  => $repository->getName(),
				], $output, $verbose
			);
		} else {
			if ( ! Utils\is_snapshot_cached( $snapshot_id ) ) {
				Log::instance()->write( 'Snapshot not found locally.', 0, 'error' );

				return 1;
			}

			$snapshot = Snapshot::get( $snapshot_id );
		}

		if ( ! is_a( $snapshot, '\WPSnapshots\Snapshot' ) ) {
			return 1;
		}

		if ( $snapshot->push() ) {
			Log::instance()->write( 'Push finished!' . ( empty( $snapshot_id ) ? ' Snapshot ID is ' . $snapshot->id : '' ), 0, 'success' );
		}
	}

}
